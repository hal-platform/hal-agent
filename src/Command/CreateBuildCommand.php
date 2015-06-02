<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MCP\DataType\Time\Clock;
use QL\Hal\Agent\Github\ReferenceResolver;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\User;
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
        1 => 'Application not found.',
        2 => 'Environment not found.',
        4 => 'User not found.',
        8 => 'Invalid git reference specified.'
    ];

    /**
     * @type EntityManagerInterface
     */
    private $em;

    /**
     * @type Clock
     */
    private $clock;

    /**
     * @type EntityRepository
     */
    private $buildRepo;
    private $applicationRepo;
    private $environmentRepo;
    private $userRepo;

    /**
     * @type ReferenceResolver
     */
    private $refResolver;

    /**
     * @type JobIdGenerator
     */
    private $unique;

    /**
     * @param string $name
     * @param EntityManagerInterface $em
     * @param Clock $clock
     * @param ReferenceResolver $refResolver
     * @param JobIdGenerator $unique
     */
    public function __construct(
        $name,
        EntityManagerInterface $em,
        Clock $clock,
        ReferenceResolver $refResolver,
        JobIdGenerator $unique
    ) {
        parent::__construct($name);

        $this->clock = $clock;

        $this->em = $em;
        $this->buildRepo = $em->getRepository(Build::CLASS);
        $this->applicationRepo = $em->getRepository(Application::CLASS);
        $this->environmentRepo = $em->getRepository(Environment::CLASS);
        $this->userRepo = $em->getRepository(User::CLASS);

        $this->refResolver = $refResolver;
        $this->unique = $unique;
    }

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Deploy a previously built application to a server.')
            ->addArgument(
                'APPLICATION_ID',
                InputArgument::REQUIRED,
                'The ID of the application to build.'
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
     * Run the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $applicationId = $input->getArgument('APPLICATION_ID');
        $environmentId = $input->getArgument('ENVIRONMENT_ID');
        $ref = $input->getArgument('GIT_REFERENCE');
        $userId = $input->getArgument('USER_ID');

        if (!$application = $this->applicationRepo->find($applicationId)) {
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
            $application->githubOwner(),
            $application->githubRepo(),
            $ref
        );

        if (!$commitSha) {
            return $this->failure($output, 8);
        }

        $build = (new Build)
            ->withId($this->unique->generateBuildId())
            ->withCreated($this->clock->read())
            ->withStatus('Waiting')
            ->withapplication($application)
            ->withEnvironment($environment)

            ->withCommit($commitSha)
            ->withBranch($ref);

        if ($user) {
            $build->withUser($user);
        }

        $this->dupeCatcher($build);

        $this->em->persist($build);
        $this->em->flush();

        if ($input->getOption('porcelain')) {
            $output->writeln($build->id());

        } else {
            $this->success($output, sprintf('Build created: %s', $build->id()));
        }
    }

    /**
     * @param Build $build
     * @return null
     */
    private function dupeCatcher(Build $build)
    {
        $dupe = $this->buildRepo->findBy(['id' => [$build->id()]]);
        if ($dupe) {
            $build->withId($this->unique->generateBuildId());
            $this->dupeCatcher($build);
        }
    }
}
