<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Management;

use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Hal\Agent\Application\HalClient;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use Hal\Agent\Executor\Runner\BuildCommand;
use Hal\Core\Entity\Application;
use QL\MCP\Common\GUID;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

class StartBuildCommand implements ExecutorInterface
{
    use ExecutorTrait;

    private const STEPS = [];

    private const COMMAND_TITLE = 'Create and run a build';
    private const MSG_SUCCESS = 'Build created.';

    private const ERR_NO_APPLICATION = 'Application not found.';
    private const ERR_API_ERROR = 'An error was returned from the API.';

    private const STATIC_HELP = <<<'HELP'
<fg=cyan>Supported git reference types:</fg=cyan>
<info>Branch</info> {BRANCH_NAME}
<info>Commit</info> {40_CHARACTER_SHA}
<info>Tag</info> tag/{TAG_NAME}
<info>Pull Request</info> pull/{PULL_REQUEST_NUMBER}
HELP;

    private const HELP_APPLICATION = 'The ID or name of the application.';
    private const HELP_ENVIRONMENT = 'The ID or name of the environment.';
    private const HELP_REF = 'The VCS reference to build.';

    /**
     * @var ObjectRepository
     */
    private $applicationRepo;

    /**
     * @var BuildCommand
     */
    private $runner;

    /**
     * @var HalClient
     */
    private $hal;

    /**
     * @param EntityManagerInterface $em
     * @param BuildCommand $runner
     * @param HalClient $hal
     */
    public function __construct(EntityManagerInterface $em, BuildCommand $runner, HalClient $hal)
    {
        $this->applicationRepo = $em->getRepository(Application::class);

        $this->runner = $runner;
        $this->hal = $hal;
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
            ->setAliases(['build'])

            ->addArgument('APPLICATION', InputArgument::REQUIRED, self::HELP_APPLICATION)
            ->addArgument('ENVIRONMENT', InputArgument::REQUIRED, self::HELP_ENVIRONMENT)
            ->addArgument('VCS_REFERENCE', InputArgument::REQUIRED, self::HELP_REF);
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $exit = $this->create($io);

        if (is_int($exit)) {
            return $exit;
        }

        $newIO = $this->buildIO([BuildCommand::PARAM_BUILD => $exit['id'] ?? '']);

        return $this->runner->execute($newIO);
    }

    /**
     * Return the API response or exit code on failure.
     *
     * @param IOInterface $io
     *
     * @return array|int
     */
    public function create(IOInterface $io)
    {
        $application = $io->getArgument('APPLICATION');
        $environment = $io->getArgument('ENVIRONMENT');
        $ref = $io->getArgument('VCS_REFERENCE');

        $io->title(self::COMMAND_TITLE);

        $environment = $this->findEnvironment($environment);
        if (!$application = $this->findApplication($application)) {
            return $this->failure($io, self::ERR_NO_APPLICATION);
        }

        $build = $this->hal->createBuild($application->id(), $environment, $ref);
        if (!$build) {
            $io->error($this->hal->apiErrors());
            return $this->failure($io, self::ERR_API_ERROR);
        }

        $id = $build['id'] ?? 'Unknown';
        $reference = $build['reference'] ?? 'Unknown';
        $commitSHA = $build['commit'] ?? 'Unknown';
        $pageURL = $build['_links']['page']['href'] ?? 'Unknown';

        $application = $build['_embedded']['application'] ?? [];
        $environment = $build['_embedded']['environment'] ?? [];

        $applicationName = $application['name'] ?? 'Unknown';
        $environmentName = $environment['name'] ?? 'Any';

        // todo - update to embedded VCS
        $vcs = $application['_links']['vcs_provider']['title'] ?? 'Unknown';

        $io->section('Details');
        $io->listing([
            sprintf('Application: <info>%s</info>', $applicationName),
            sprintf('Environment: <info>%s</info>', $environmentName),
        ]);

        $io->section('Build Information');
        $io->listing([
            sprintf('ID: <info>%s</info>', $id),
            sprintf('URL: <info>%s</info>', $pageURL),
            sprintf('VCS: <info>%s</info>', $vcs),
            sprintf('Reference: <info>%s</info> (%s)', $reference, $commitSHA)
        ]);

        $this->success($io, self::MSG_SUCCESS);
        return $build;
    }

    /**
     * Find application from ID or name.
     *
     * @param string $name
     *
     * @return Application|null
     */
    private function findApplication($name)
    {
        // todo - need a better way to auto fail if not guidy
        $isGUID = GUID::createFromHex($name);

        if ($isGUID && $application = $this->applicationRepo->find($name)) {
            return $application;
        }

        if ($application = $this->applicationRepo->findOneBy(['name' => $name])) {
            return $application;
        }

        return null;
    }

    /**
     * @param string $name
     *
     * @return string|null
     */
    private function findEnvironment($name)
    {
        if (strtolower($name) === 'any') {
            return null;
        }

        return $name;
    }
}
