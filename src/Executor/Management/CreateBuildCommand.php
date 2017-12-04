<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Management;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use Hal\Agent\Github\ReferenceResolver;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Application\GitHubApplication;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Environment;
use QL\MCP\Common\Time\Clock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

class CreateBuildCommand implements ExecutorInterface
{
    use ExecutorTrait;

    const COMMAND_TITLE = 'Create build';
    const MSG_SUCCESS = 'Build created.';

    const ERR_NO_APPLICATION = 'Application not found.';
    const ERR_NO_ENVIRONMENT = 'Environment not found.';
    const ERR_NO_REF = 'Invalid git reference specified.';

    const STATIC_HELP = <<<'HELP'
<fg=cyan>Supported git reference types:</fg=cyan>
<info>Branch</info> {BRANCH_NAME}
<info>Commit</info> {40_CHARACTER_SHA}
<info>Tag</info> tag/{TAG_NAME}
<info>Pull Request</info> pull/{PULL_REQUEST_NUMBER}
HELP;

    const HELP_APPLICATION = 'The ID or key of the application to build.';
    const HELP_ENVIRONMENT = 'The ID or name of the environment to build.';
    const HELP_REF = 'The git reference to build.';

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var EntityRepository
     */
    private $applicationRepo;
    private $environmentRepo;

    /**
     * @var ReferenceResolver
     */
    private $refResolver;

    /**
     * @var Build|null
     */
    private $build;

    /**
     * @param EntityManagerInterface $em
     * @param Clock $clock
     * @param ReferenceResolver $refResolver
     */
    public function __construct(
        EntityManagerInterface $em,
        Clock $clock,
        ReferenceResolver $refResolver
    ) {
        $this->clock = $clock;

        $this->em = $em;
        $this->applicationRepo = $em->getRepository(Application::class);
        $this->environmentRepo = $em->getRepository(Environment::class);

        $this->refResolver = $refResolver;
    }

    /**
     * @param Command $command
     *
     * @return void
     */
    public static function configure(Command $command)
    {
        $command
            ->setDescription('Create a build for an environment.')
            ->setHelp(self::STATIC_HELP)

            ->addArgument('APPLICATION', InputArgument::REQUIRED, self::HELP_APPLICATION)
            ->addArgument('ENVIRONMENT', InputArgument::REQUIRED, self::HELP_ENVIRONMENT)
            ->addArgument('GIT_REFERENCE', InputArgument::REQUIRED, self::HELP_REF);
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $application = $io->getArgument('APPLICATION');
        $environment = $io->getArgument('ENVIRONMENT');
        $ref = $io->getArgument('GIT_REFERENCE');

        $io->title(self::COMMAND_TITLE);

        if (!$application = $this->findApplication($application)) {
            return $this->failure($io, self::ERR_NO_APPLICATION);
        }

        if ($environment === 'global') {
            $environment = null;
        } elseif (!$environment = $this->findEnvironment($environment)) {
            return $this->failure($io, self::ERR_NO_ENVIRONMENT);
        }

        if (!$commitSha = $this->validateReference($application->gitHub(), $ref)) {
            return $this->failure($io, self::ERR_NO_REF);
        }

        $build = (new Build())
            ->withStatus('pending')

            ->withApplication($application)
            ->withEnvironment($environment)

            ->withCommit($commitSha)
            ->withReference($ref);

        $this->em->persist($build);
        $this->em->flush();

        $repo = sprintf('%s/%s', $application->gitHub()->owner(), $application->gitHub()->repository());

        $io->section('Details');
        $io->listing([
            sprintf('Application: <info>%s</info>', $application->identifier()),
            sprintf('Environment: <info>%s</info>', $environment ? $environment->name() : 'Any' ),
        ]);

        $io->section('Build Information');
        $io->listing([
            sprintf('ID: <info>%s</info>', $build->id()),
            sprintf('Source: <info>%s</info>', $repo),
            sprintf('Reference: <info>%s</info> (%s)', $ref, $commitSha)
        ]);

        $this->build = $build;
        $this->success($io, self::MSG_SUCCESS);
    }

    /**
     * Used to expose the created build to other commands.
     *
     * It's rather hacky.
     *
     * @return Build|null
     */
    public function build()
    {
        return $this->build;
    }

    /**
     * Find application from ID or identifier.
     *
     * @param string $app
     *
     * @return Application|null
     */
    private function findApplication($app)
    {
        if ($application = $this->applicationRepo->find($app)) {
            return $application;
        }

        if ($application = $this->applicationRepo->findOneBy(['identifier' => $app])) {
            return $application;
        }

        return null;
    }

    /**
     * Find environment from ID or name.
     *
     * @param string $env
     *
     * @return Environment|null
     */
    private function findEnvironment($env)
    {
        if ($environment = $this->environmentRepo->find($env)) {
            return $environment;
        }

        if ($environment = $this->environmentRepo->findOneBy(['name' => $env])) {
            return $environment;
        }

        return null;
    }

    /**
     * Find a commit sha from a reference.
     *
     * @param GitHubApplication $application
     * @param string $ref
     *
     * @return null|string
     */
    private function validateReference(GitHubApplication $application, $ref)
    {
        $commitSha = $this->refResolver->resolve(
            $application->owner(),
            $application->repository(),
            $ref
        );

        return $commitSha;
    }
}
