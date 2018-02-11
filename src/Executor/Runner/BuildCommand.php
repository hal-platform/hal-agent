<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Runner;

use Hal\Agent\Build\Artifacter;
use Hal\Agent\Build\BuildException;
use Hal\Agent\Build\Downloader;
use Hal\Agent\Build\Resolver;
use Hal\Agent\Job\LocalCleaner;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use Hal\Agent\Executor\JobStatsTrait;
use Hal\Agent\JobConfiguration\ConfigurationReader;
use Hal\Agent\JobExecution;
use Hal\Agent\JobRunner;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\SSHSessionManager;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Type\JobEventStageEnum;
use Hal\Core\Type\VCSProviderEnum;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

class BuildCommand implements ExecutorInterface
{
    use ExecutorTrait;
    use FormatterTrait;
    use JobStatsTrait;

    const COMMAND_TITLE = 'Runner - Run build';
    const MSG_SUCCESS = 'Build was run successfully.';

    const PARAM_BUILD = 'BUILD_ID';
    const HELP_BUILD = 'The ID of the Build to run.';

    const STEPS = [
        1 => 'Resolving configuration',
        2 => 'Downloading source code and preparing workspace',
        3 => 'Reading .hal.yml configuration',
        4 => 'Running build stage',
        5 => 'Storing build artifact'
    ];

    private const ERR_NOT_RUNNABLE = 'Build cannot be run.';
    private const ERR_DOWNLOAD = 'Source code cannot be downloaded.';
    private const ERR_CONFIG = '.hal.yaml configuration is invalid and cannot be read.';
    private const ERR_BUILD = 'Build stage failed.';
    private const ERR_STORE_ARTIFACT = 'Build artifact cannot be stored and exported to artifact repository.';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var LocalCleaner
     */
    private $cleaner;

    /**
     * @var SSHSessionManager
     */
    private $sshManager;

    /**
     * @var Resolver
     */
    private $resolver;

    /**
     * @var Downloader
     */
    private $downloader;

    /**
     * @var ConfigurationReader
     */
    private $reader;

    /**
     * @var JobRunner
     */
    private $builder;

    /**
     * @var Artifacter
     */
    private $artifacter;

    /**
     * @var bool
     */
    private $enableShutdownHandler;

    /**
     * @var callable|null
     */
    private $cleanup;

    /**
     * @param EventLogger $logger
     * @param LocalCleaner $cleaner
     * @param SSHSessionManager $sshManager
     *
     * @param Resolver $resolver
     * @param Downloader $downloader
     * @param ConfigurationReader $reader
     * @param JobRunner $builder
     * @param Artifacter $artifacter
     */
    public function __construct(
        EventLogger $logger,
        LocalCleaner $cleaner,
        SSHSessionManager $sshManager,

        Resolver $resolver,
        Downloader $downloader,
        ConfigurationReader $reader,
        JobRunner $builder,
        Artifacter $artifacter
    ) {
        $this->logger = $logger;
        $this->cleaner = $cleaner;
        $this->sshManager = $sshManager;

        $this->resolver = $resolver;
        $this->downloader = $downloader;
        $this->reader = $reader;
        $this->builder = $builder;
        $this->artifacter = $artifacter;

        $this->enableShutdownHandler = false;
        $this->startTimer();
    }

    /**
     * In case of error or critical failure, ensure that we clean up the build artifacts.
     *
     * Note that this is only called for exceptions and non-fatal errors. Fatal errors WILL NOT trigger this.
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->enableShutdownHandler) {
            $this->emergencyCleanup();
        }
    }

    /**
     * @return void
     */
    public function setShutdownHandler(bool $enabled): void
    {
        $this->enableShutdownHandler = $enabled;
    }

    /**
     * @param Command $command
     *
     * @return void
     */
    public static function configure(Command $command)
    {
        $command
            ->setDescription('Run a build for an application.')

            ->addArgument(self::PARAM_BUILD, InputArgument::REQUIRED, self::HELP_BUILD);
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $buildID = $io->getArgument(self::PARAM_BUILD);

        $io->title(self::COMMAND_TITLE);

        $this->logger->setStage(JobEventStageEnum::TYPE_CREATED);

        if (!$properties = $this->prepareAgentConfiguration($io, $buildID)) {
            return $this->buildFailure($io, self::ERR_NOT_RUNNABLE);
        }

        $job = $properties['job'];

        // The build has officially started running.
        // Set the build to in progress
        $this->logger->start($job);
        $this->logger->event('success', 'Resolved build configuration', [
            'defaultConfiguration' => $properties['default_configuration'],
            'encryptedConfiguration' => $properties['encrypted_sources'] ?? [],
        ]);

        if (!$this->downloadSourceCode($io, $job, $properties['workspace_path'])) {
            return $this->buildFailure($io, self::ERR_DOWNLOAD);
        }

        if (!$config = $this->loadConfiguration($io, $properties['default_configuration'], $properties['workspace_path'])) {
            return $this->buildFailure($io, self::ERR_CONFIG);
        }

        if (!$this->build($io, $job, $config, $properties)) {
            return $this->buildFailure($io, self::ERR_BUILD);
        }

        $this->logger->setStage(JobEventStageEnum::TYPE_ENDING);

        if (!$this->storeArtifact($io, $config, $properties['artifact_stored_file'], $properties['workspace_path'])) {
            return $this->buildFailure($io, self::ERR_STORE_ARTIFACT);
        }

        $this->outputJobStats($io);
        return $this->buildSuccess($io, self::MSG_SUCCESS);
    }

    /**
     * @param IOInterface $io
     * @param string $buildID
     *
     * @return array|null
     */
    private function prepareAgentConfiguration(IOInterface $io, $buildID)
    {
        $io->section($this->step(1));

        try {
            $properties = ($this->resolver)($buildID);
        } catch (BuildException $ex) {
            $io->caution($ex->getMessage());
            $properties = null;
        }

        if (!$properties) {
            return null;
        }

        // We must set an emergency handler in case of super fatal to ensure it is always
        // safely finished and clean-up is run.
        $this->setCleanupHandler($io, $properties['workspace_path']);

        $this->outputJobInformation($properties['job'], $io, $properties);
        return $properties;
    }

    /**
     * @param Build $build
     * @param IOInterface $io
     * @param array $properties
     *
     * @return void
     */
    private function outputJobInformation(Build $build, IOInterface $io, array $properties)
    {
        $application = $build->application();
        $environment = $build->environment();

        $applicationName = $application ? $application->name() : 'Unknown';
        $applicationID = $application ? $application->id() : 'N/A';
        $environmentName = $environment ? $environment->name() : 'None';
        $environmentID = $environment ? $environment->id() : 'N/A';

        $io->listing([
            sprintf('Build: %s', $this->colorize($build->id())),
            sprintf('Application: %s (ID: %s)', $this->colorize($applicationName), $applicationID),
            sprintf('Environment: %s (ID: %s)', $this->colorize($environmentName), $environmentID)
        ]);

        $outputConfig = array_intersect_key($properties, array_fill_keys(['encrypted_sources', 'artifacts', 'artifact_stored_file'], 1));
        $this->outputTable($io, 'Agent configuration:', $outputConfig);
    }

    /**
     * @param IOInterface $io
     * @param Build $build
     * @param string $workspacePath
     *
     * @return bool
     */
    private function downloadSourceCode(IOInterface $io, Build $build, string $workspacePath)
    {
        $io->section($this->step(2));

        $provider = $build->application()->provider();
        $providerName = $provider ? $provider->name() : 'None';
        $provideType = $provider ? VCSProviderEnum::format($provider->type()) : 'N/A';

        $io->listing([
            sprintf('Build Workspace: %s', $this->colorize($workspacePath)),
            sprintf('VCS Provider: %s (Type: %s)', $this->colorize($providerName), $provideType),
            sprintf('VCS Reference: %s (Commit: %s)', $this->colorize($build->reference()), $build->commit())
        ]);

        return ($this->downloader)($build, $workspacePath);
    }

    /**
     * @param IOInterface $io
     * @param array $defaultConfiguration
     * @param string $workspacePath
     *
     * @return array|null
     */
    private function loadConfiguration(IOInterface $io, array $defaultConfiguration, string $workspacePath): ?array
    {
        $io->section($this->step(3));

        $buildPath = $workspacePath . '/build';

        $config = ($this->reader)($buildPath, $defaultConfiguration);

        if (!$config) {
            return null;
        }

        $this->outputTable($io, 'Application configuration:', $config);

        return $config;
    }

    /**
     * @param IOInterface $io
     * @param Build $build
     * @param array $config
     * @param array $properties
     *
     * @return bool
     */
    private function build(IOInterface $io, Build $build, array $config, array $properties)
    {
        $io->section($this->step(4));

        $platform = $config['platform'];
        $image = $config['image'];
        $steps = $config['build'];

        $execution = new JobExecution($platform, 'build', $config);

        if (!$steps) {
            $io->text('No steps found. Skipping...');
            return true;
        }

        $io->listing([
            sprintf('Platform: %s', $this->colorize($platform)),
            sprintf('Docker Image: %s', $this->colorize($image))
        ]);

        $io->text('Running steps:');
        $io->listing($this->colorize($steps));

        return ($this->builder)($build, $io, $execution, $properties);
    }

    /**
     * @param IOInterface $io
     * @param array $config
     * @param string $storedArtifactFile
     * @param string $workspacePath
     *
     * @return bool
     */
    private function storeArtifact(IOInterface $io, array $config, string $storedArtifactFile, string $workspacePath)
    {
        $io->section($this->step(5));

        $buildPath = $workspacePath . '/build';
        $artifactFile = $workspacePath . '/artifact.tgz';

        $io->listing([
            sprintf('Artifact Path: %s', $this->colorize($buildPath . '/' . $config['dist'])),
            sprintf('Artifact File: %s', $this->colorize($artifactFile)),

            sprintf('Artifact Repository: %s', $this->colorize('Filesystem')),
            sprintf('Repository Location: %s', $this->colorize($storedArtifactFile))
        ]);

        return ($this->artifacter)($buildPath, $config['dist'], $artifactFile, $storedArtifactFile);
    }

    /**
     * @param IOInterface $io
     * @param string $workspacePath
     *
     * @return void
     */
    private function setCleanupHandler(IOInterface $io, $workspacePath)
    {
        $cleanup = function () use ($io, $workspacePath) {
            $artifacts = [$workspacePath];

            $io->newLine();
            $io->section('Build clean-up');

            $io->text('Build artifacts to remove:');
            $io->listing($this->colorize($artifacts));

            $io->text('Disconnecting all open SSH connections.');
            $io->newLine();

            $this->sshManager->disconnectAll();

            ($this->cleaner)($artifacts);
        };

        $this->cleanup = $cleanup;
        if ($this->enableShutdownHandler) {
            // protip: avoid [$this, 'method'] notation, it makes searching and refactoring more difficult.
            register_shutdown_function(function() {
                $this->emergencyCleanup();
            });
        }
    }

    /**
     * Cleanup (if prepared) should only run once. So successive calls to this method will have no effect.
     *
     * This is useful because we can attach cleanup events to many processes
     * (error handler, destruct, success, etc) and not worry about duplicate calls.
     *
     * @return void
     */
    private function cleanup()
    {
        if ($this->cleanup) {
            ($this->cleanup)();
        }

        $this->cleanup = null;
    }

    /**
     * @param IOInterface $io
     * @param string $message
     *
     * @return int
     */
    private function buildFailure(IOInterface $io, $message)
    {
        $this->logger->failure();
        $this->cleanup();

        return $this->failure($io, $message);
    }

    /**
     * @param IOInterface $io
     * @param string $message
     *
     * @return int
     */
    private function buildSuccess(IOInterface $io, $message)
    {
        $this->logger->success();
        $this->cleanup();

        return $this->success($io, $message);
    }

    /**
     * Emergency failsafe
     */
    public function emergencyCleanup()
    {
        $this->cleanup();

        // If we got to this point and the status is still "Building", something terrible has happened.
        // The EventLogger will only fail builds that aren't finished yet.
        $this->logger->failure();
    }
}
