<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Doctrine\ORM\EntityManager;
use MCP\DataType\Time\Clock;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Repository\BuildRepository;
use QL\Hal\Core\Entity\Repository\DeploymentRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create a push job
 */
class CreatePushCommand extends Command
{
    use CommandTrait;
    use FormatterTrait;

    /**
     * A list of all possible exit codes of this command
     *
     * @var array
     */
    private static $codes = [
        0 => 'Success',
        1 => 'Build not found.',
        2 => 'Provided build was not successful! It cannot be pushed.',
        4 => 'Deployment not found.'
    ];

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var BuildRepository
     */
    private $buildRepo;

    /**
     * @var DeploymentRepository
     */
    private $deploymentRepo;

    /**
     * @param string $name
     * @param EntityManager $entityManager
     * @param Clock $clock
     * @param BuildRepository $buildRepo
     * @param DeploymentRepository $deploymentRepo
     */
    public function __construct(
        $name,
        EntityManager $entityManager,
        Clock $clock,
        BuildRepository $buildRepo,
        DeploymentRepository $deploymentRepo
    ) {
        parent::__construct($name);

        $this->entityManager = $entityManager;
        $this->clock = $clock;
        $this->buildRepo = $buildRepo;
        $this->deploymentRepo = $deploymentRepo;
    }

    /**
     *  Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Deploy a previously built application to a server.')
            ->addArgument(
                'BUILD_ID',
                InputArgument::REQUIRED,
                'The ID of the build to deploy.'
            )
            ->addArgument(
                'DEPLOYMENT_ID',
                InputArgument::REQUIRED,
                'The ID of the deployment relationship.'
            )
            ->addOption(
                'porcelain',
                null,
                InputOption::VALUE_NONE,
                'If set, Only the build ID will be returned.'
            );

        $errors = ['Exit Codes:'];
        foreach (static::$codes as $code => $message) {
            $errors[] = $this->formatSection($code, $message);
        }
        $this->setHelp(implode("\n", $errors));
    }

    /**
     *  Run the command
     *
     *  @param InputInterface $input
     *  @param OutputInterface $output
     *  @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $buildId = $input->getArgument('BUILD_ID');
        $deploymentId = $input->getArgument('DEPLOYMENT_ID');

        if (!$build = $this->buildRepo->find($buildId)) {
            return $this->failure($output, 1);
        }

        if ($build->getStatus() !== 'Success') {
            return $this->failure($output, 2);
        }

        if (!$deployment = $this->deploymentRepo->find($deploymentId)) {
            return $this->failure($output, 4);
        }

        $push = new Push;
        $push->setStatus('Waiting');
        $push->setBuild($build);
        $push->setDeployment($deployment);

        $this->entityManager->persist($push);
        $this->entityManager->flush();

        if ($input->getOption('porcelain')) {
            $output->writeln($push->getId());

        } else {
            $this->success($output, sprintf('Push created: %s', $push->getId()));
        }
    }
}
