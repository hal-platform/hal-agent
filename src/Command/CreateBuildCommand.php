<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Doctrine\ORM\EntityManager;
use MCP\DataType\Time\Clock;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Repository\EnvironmentRepository;
use QL\Hal\Core\Entity\Repository\RepositoryRepository;
use QL\Hal\Core\Entity\Repository\UserRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create a build job
 */
class CreateBuildCommand extends Command
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
        1 => 'Repository not found.',
        2 => 'Environment not found.',
        4 => 'User not found.'
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
     * @var RepositoryRepository
     */
    private $repoRepo;

    /**
     * @var EnvironmentRepository
     */
    private $environmentRepo;

    /**
     * @var UserRepository
     */
    private $userRepo;

    /**
     * @param string $name
     * @param EntityManager $entityManager
     * @param Clock $clock
     * @param RepositoryRepository $repoRepo
     * @param EnvironmentRepository $environmentRepo
     * @param UserRepository $userRepo
     */
    public function __construct(
        $name,
        EntityManager $entityManager,
        Clock $clock,
        RepositoryRepository $repoRepo,
        EnvironmentRepository $environmentRepo,
        UserRepository $userRepo
    ) {
        parent::__construct($name);

        $this->entityManager = $entityManager;
        $this->clock = $clock;
        $this->repoRepo = $repoRepo;
        $this->environmentRepo = $environmentRepo;
        $this->userRepo = $userRepo;
    }

    /**
     *  Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Deploy a previously built application to a server.')
            ->addArgument(
                'REPOSITORY_ID',
                InputArgument::REQUIRED,
                'The ID of the repository to build.'
            )
            ->addArgument(
                'ENVIRONMENT_ID',
                InputArgument::REQUIRED,
                'The ID of the environment to build for.'
            )
            ->addArgument(
                'GIT_REF',
                InputArgument::REQUIRED,
                'The git reference to build.'
            )
            ->addArgument(
                'USER_ID',
                InputArgument::OPTIONAL,
                'The user that triggered the build.'
            )
            ->addOption(
                'porcelain',
                null,
                InputOption::VALUE_NONE,
                'If set, only the build ID will be returned.'
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
        $repositoryId = $input->getArgument('REPOSITORY_ID');
        $environmentId = $input->getArgument('ENVIRONMENT_ID');
        $reference = $input->getArgument('GIT_REF');
        $userId = $input->getArgument('USER_ID');

        if (!$repository = $this->repoRepo->find($repositoryId)) {
            return $this->failure($output, 1);
        }

        if (!$environment = $this->environmentRepo->find($environmentId)) {
            return $this->failure($output, 2);
        }

        $user = null;
        if ($userId && !$user = $this->userRepo->find($userId)) {
            return $this->failure($output, 4);
        }

        // need to validate the ref

        $build = new Build;
        $build->setId($this->generateBuildId());
        $build->setStatus('Waiting');
        $build->setRepository($repository);
        $build->setEnvironment($environment);
        $build->setUser($user);
        $build->setCommit($reference);
        $build->setBranch('dontcare');

        $this->entityManager->persist($build);
        $this->entityManager->flush();

        if ($input->getOption('porcelain')) {
            $output->writeln($build->getId());

        } else {
            $this->success($output, sprintf('Build created: %s', $build->getId()));
        }
    }

    /**
     * @return string
     */
    private function generateBuildId()
    {
        return substr(sha1(microtime(true) . mt_rand(10000, 90000)), 0, 20);
    }
}
