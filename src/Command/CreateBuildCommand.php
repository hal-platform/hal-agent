<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Doctrine\ORM\EntityManager;
use MCP\DataType\Time\Clock;
use QL\Hal\Agent\Github\ReferenceResolver;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Repository\BuildRepository;
use QL\Hal\Core\Entity\Repository\EnvironmentRepository;
use QL\Hal\Core\Entity\Repository\RepositoryRepository;
use QL\Hal\Core\Entity\Repository\UserRepository;
use QL\Hal\Core\JobIdGenerator;
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

    const STATIC_HELP = <<<'HELP'
<fg=cyan>Supported git reference types:</fg=cyan>
<info>Branch</info> {BRANCH_NAME}
<info>Commit</info> {40_CHARACTER_SHA}
<info>Tag</info> tag/{TAG_NAME}
<info>Pull Request</info> pull/{PULL_REQUEST_NUMBER}

<fg=cyan>Exit codes:</fg=cyan>
HELP;

    /**
     * A list of all possible exit codes of this command
     *
     * @var array
     */
    private static $codes = [
        0 => 'Success',
        1 => 'Repository not found.',
        2 => 'Environment not found.',
        4 => 'User not found.',
        8 => 'Invalid git reference specified.'
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
     * @var ReferenceResolver
     */
    private $refResolver;

    /**
     * @var JobIdGenerator
     */
    private $unique;

    /**
     * @param string $name
     * @param EntityManager $entityManager
     * @param Clock $clock
     * @param BuildRepository $buildRepo
     * @param RepositoryRepository $repoRepo
     * @param EnvironmentRepository $environmentRepo
     * @param UserRepository $userRepo
     * @param ReferenceResolver $refResolver
     * @param JobIdGenerator $unique
     */
    public function __construct(
        $name,
        EntityManager $entityManager,
        Clock $clock,
        BuildRepository $buildRepo,
        RepositoryRepository $repoRepo,
        EnvironmentRepository $environmentRepo,
        UserRepository $userRepo,
        ReferenceResolver $refResolver,
        JobIdGenerator $unique
    ) {
        parent::__construct($name);

        $this->entityManager = $entityManager;
        $this->clock = $clock;
        $this->buildRepo = $buildRepo;
        $this->repoRepo = $repoRepo;
        $this->environmentRepo = $environmentRepo;
        $this->userRepo = $userRepo;
        $this->refResolver = $refResolver;
        $this->unique = $unique;
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
                'GIT_REFERENCE',
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

        $help = [self::STATIC_HELP];
        foreach (static::$codes as $code => $message) {
            $help[] = $this->formatSection($code, $message);
        }

        $this->setHelp(implode("\n", $help));
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
        $ref = $input->getArgument('GIT_REFERENCE');
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

        $commitSha = $this->refResolver->resolve(
            $repository->getGithubUser(),
            $repository->getGithubRepo(),
            $ref
        );

        if (!$commitSha) {
            return $this->failure($output, 8);
        }

        $build = new Build;
        $build->setId($this->unique->generateBuildId());
        $build->setCreated($this->clock->read());
        $build->setStatus('Waiting');
        $build->setRepository($repository);
        $build->setEnvironment($environment);
        $build->setUser($user);
        $build->setCommit($commitSha);
        $build->setBranch($ref);

        $this->dupeCatcher($build);

        $this->entityManager->persist($build);
        $this->entityManager->flush();

        if ($input->getOption('porcelain')) {
            $output->writeln($build->getId());

        } else {
            $this->success($output, sprintf('Build created: %s', $build->getId()));
        }
    }

    /**
     * @param Build $build
     * @return null
     */
    private function dupeCatcher(Build $build)
    {
        $dupe = $this->buildRepo->findBy(['id' => [$build->getId()]]);
        if ($dupe) {
            $build->setId($this->unique->generateBuildId());
            $this->dupeCatcher($build);
        }
    }
}
