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
     * @var string
     */
    private $version;

    /**
     * @param string $name
     * @param EntityManager $entityManager
     * @param Clock $clock
     * @param RepositoryRepository $repoRepo
     * @param EnvironmentRepository $environmentRepo
     * @param UserRepository $userRepo
     * @param ReferenceResolver $refResolver
     * @param string $version
     */
    public function __construct(
        $name,
        EntityManager $entityManager,
        Clock $clock,
        RepositoryRepository $repoRepo,
        EnvironmentRepository $environmentRepo,
        UserRepository $userRepo,
        ReferenceResolver $refResolver,
        $version
    ) {
        parent::__construct($name);

        $this->entityManager = $entityManager;
        $this->clock = $clock;
        $this->repoRepo = $repoRepo;
        $this->environmentRepo = $environmentRepo;
        $this->userRepo = $userRepo;
        $this->refResolver = $refResolver;
        $this->version = $version;
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
        $build->setId($this->generateBuildId());
        $build->setCreated($this->clock->read());
        $build->setStatus('Waiting');
        $build->setRepository($repository);
        $build->setEnvironment($environment);
        $build->setUser($user);
        $build->setCommit($commitSha);
        $build->setBranch($ref);

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
        $base58 = '123456789abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';

        // YYDDD 365 - 99365
        // For a consistent prefix that increments and can be used to easily find builds from a certain time set
        $day = date('y') . str_pad(date('z'), 3, '0', STR_PAD_LEFT);

        // 3364 - min 3 char
        // 195112 - min 4 char
        // 11316496 - min 5 char
        // 656356768 - min 6 char

        // 3 char = 191 748 uniq
        // 4 char = 11 121 384 uniq
        // 5 char = 645 040 272 uniq

        // get a random number that will consistently hash to 4 chars
        $rando = mt_rand(195112, 11316495);

        return sprintf(
            'b%d.%s%s',
            $this->version,
            $this->encode($day, $base58),
            $this->encode($rando, $base58)
        );
    }

    /**
     * @param int $num
     * @param string $alphabet
     * @return string
     */
    function encode($num, $alphabet)
    {
        $alphabet = str_split($alphabet);
        $base = count($alphabet);

        $encoded = '';
        while($num > 0) {
            $encoded = $alphabet[$num % $base] . $encoded;
            $num = floor($num / $base);
        }

        return $encoded;
    }
}
