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
 *  Create a push job
 */
class CreatePushCommand extends Command
{
    /**
     * @var string
     */
    const ERR_BUILD_NOT_FOUND = '<error>Build ID "%s" not found.</error>';
    const ERR_DEPLOY_NOT_FOUND = '<error>Deployment ID "%s" not found.</error>';
    const ERR_BUILD_STATUS = '<error>Build "%s" has a status of "%s"! It cannot be pushed.</error>';

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
                'If set, Only the build id will be returned'
            );
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
            $output->writeln(sprintf(self::ERR_BUILD_NOT_FOUND, $buildId));
            return 1;
        }

        if ($build->getStatus() !== 'Success') {
            $output->writeln(sprintf(self::ERR_BUILD_STATUS, $buildId, $build->getStatus()));
            return 2;
        }

        if (!$deployment = $this->deploymentRepo->find($deploymentId)) {
            $output->writeln(sprintf(self::ERR_DEPLOY_NOT_FOUND, $deploymentId));
            return 4;
        }

        $push = new Push;
        $push->setStatus('Waiting');
        $push->setBuild($build);
        $push->setDeployment($deployment);

        $this->entityManager->persist($push);
        $this->entityManager->flush();

        $id = $push->getId();
        $text = $id;

        if (!$input->getOption('porcelain')) {
            $text = sprintf('<question>Push created: %s</question>', $id);
        }

        $output->writeln($text);
    }
}
