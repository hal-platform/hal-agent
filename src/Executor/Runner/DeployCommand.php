<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Runner;

use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Deploy\Artifacter;
use Hal\Agent\Deploy\DeployException;
use Hal\Agent\Deploy\Resolver;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use Hal\Agent\Executor\JobStatsTrait;
use Hal\Agent\JobExecution;
use Hal\Agent\JobRunner;
use Hal\Agent\Job\LocalCleaner;
use Hal\Agent\JobConfiguration\ConfigurationReader;
use Hal\Agent\Logger\EventLogger;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Type\JobEventStageEnum;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

class DeployCommand implements ExecutorInterface
{
    use ExecutorTrait;
    use FormatterTrait;
    use JobStatsTrait;

    const COMMAND_TITLE = 'Runner - Deploy release';
    const MSG_SUCCESS = 'Release was deployed successfully.';

    const PARAM_RELEASE = 'RELEASE_ID';
    const HELP_RELEASE = 'The ID of the Release to deploy.';

    const DEPLOY_STATUS_VAR = 'HAL_DEPLOY_STATUS';
    const DEPLOY_STATUS_PENDING = 'pending';
    const DEPLOY_STATUS_RUNNING = 'running';
    const DEPLOY_STATUS_SUCCESS = 'success';
    const DEPLOY_STATUS_FAILURE = 'failure';

    const STEPS = [
        1 => 'Resolving configuration',
        2 => 'Downloading build artifact',
        3 => 'Reading .hal.yml configuration',
        4 => 'Running build transform stage',
        5 => 'Running before deployment stage',
        6 => 'Running deployment stage',
        7 => 'Running after deployment stage'
    ];

    private const ERR_NOT_RUNNABLE = 'Release cannot be run.';
    private const ERR_DOWNLOAD = 'Build artifact cannot be downloaded from artifact repository.';
    private const ERR_CONFIG = '.hal.yaml configuration is invalid and cannot be read.';
    private const ERR_TRANSFORM = 'Build transform process failed.';
    private const ERR_BEFORE_DEPLOYMENT = 'Before deployment stage failed.';
    private const ERR_DEPLOY = 'Deployment stage failed.';
    private const ERR_AFTER_DEPLOYMENT = 'After deployment stage failed.';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var LocalCleaner
     */
    private $cleaner;

    /**
     * @var Resolver
     */
    private $resolver;

    /**
     * @var Artifacter
     */
    private $artifacter;

    /**
     * @var ConfigurationReader
     */
    private $reader;

    /**
     * @var JobRunner
     */
    private $builder;

    /**
     * @var JobRunner
     */
    private $deployer;

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
     *
     * @param Resolver $resolver
     * @param Artifacter $artifacter
     * @param ConfigurationReader $reader
     * @param JobRunner $builder
     * @param JobRunner $deployer
     */
    public function __construct(
        EventLogger $logger,
        LocalCleaner $cleaner,
        Resolver $resolver,
        Artifacter $artifacter,
        ConfigurationReader $reader,
        JobRunner $builder,
        JobRunner $deployer
    ) {
        $this->logger = $logger;
        $this->cleaner = $cleaner;

        $this->resolver = $resolver;
        $this->artifacter = $artifacter;
        $this->reader = $reader;

        $this->builder = $builder;
        $this->deployer = $deployer;

        $this->enableShutdownHandler = false;
        $this->startTimer();
    }

    /**
     * In case of error or critical failure, ensure that we clean up the build artifacts.
     *
     * Note that this is only called for exceptions and non-fatal errors.
     * Fatal errors WILL NOT trigger this.
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
            ->setDescription('Run a deployment for an environment target.')

            ->addArgument(self::PARAM_RELEASE, InputArgument::REQUIRED, self::HELP_RELEASE);
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $releaseID = $io->getArgument(self::PARAM_RELEASE);

        $io->title(self::COMMAND_TITLE);

        $this->logger->setStage(JobEventStageEnum::TYPE_CREATED);

        if (!$properties = $this->prepareAgentConfiguration($io, $releaseID)) {
            return $this->deploymentFailure($io, self::ERR_NOT_RUNNABLE);
        }

        $job = $properties['job'];

        // The release has officially started running.
        // Set the release to in progress
        $this->logger->start($job);
        $this->logger->event('success', 'Resolved deployment configuration', [
            'defaultConfiguration' => $properties['default_configuration'],
            'encryptedConfiguration' => $properties['encrypted_sources'] ?? [],
        ]);

        if (!$this->downloadArtifacts($io, $job, $properties['workspace_path'])) {
            return $this->deploymentFailure($io, self::ERR_DOWNLOAD);
        }

        if (!$config = $this->loadConfiguration($io, $properties['default_configuration'], $properties['workspace_path'])) {
            return $this->deploymentFailure($io, self::ERR_CONFIG);
        }

        if (!$this->transform($io, $job, $config, $properties)) {
            return $this->deploymentFailure($io, self::ERR_TRANSFORM);
        }

        // before deploy
        $config = $this->appendDeploymentStatus($config, self::DEPLOY_STATUS_PENDING);
        if (!$this->beforeDeploy($io, $job, $config, $properties)) {
            return $this->deploymentFailure($io, self::ERR_BEFORE_DEPLOYMENT);
        }

        $config = $this->appendDeploymentStatus($config, self::DEPLOY_STATUS_RUNNING);
        $isDeploySuccess = $this->deploy($io, $job, $properties['platform'], $config, $properties);

        $this->logger->setStage(JobEventStageEnum::TYPE_ENDING);

        // after deploy
        $config = $this->appendDeploymentStatus($config, $isDeploySuccess ? self::DEPLOY_STATUS_SUCCESS : self::DEPLOY_STATUS_FAILURE);
        if (!$this->afterDeploy($io, $job, $config, $properties)) {
            return $this->deploymentFailure($io, self::ERR_AFTER_DEPLOYMENT);
        }

        if (!$isDeploySuccess) {
            return $this->deploymentFailure($io, self::ERR_DEPLOY);
        }

        $this->outputJobStats($io);
        return $this->deploymentSuccess($io, self::MSG_SUCCESS);
    }

    /**
     * @param IOInterface $io
     * @param string $releaseID
     *
     * @return array|null
     */
    private function prepareAgentConfiguration(IOInterface $io, $releaseID)
    {
        $io->section($this->step(1));

        try {
            $properties = ($this->resolver)($releaseID);
        } catch (DeployException $ex) {
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
     * @param Release $release
     * @param IOInterface $io
     * @param array $properties
     *
     * @return void
     */
    private function outputJobInformation(Release $release, IOInterface $io, array $properties)
    {
        $build = $release->build();
        $application = $release->application();
        $environment = $release->environment();

        $applicationName = $application ? $application->name() : 'Unknown';
        $applicationID = $application ? $application->id() : 'N/A';
        $environmentName = $environment ? $environment->name() : 'None';
        $environmentID = $environment ? $environment->id() : 'N/A';

        $io->listing([
            sprintf('Release: %s', $this->colorize($release->id())),
            sprintf('Build: %s', $this->colorize($build->id())),
            sprintf('Application: %s (ID: %s)', $this->colorize($applicationName), $applicationID),
            sprintf('Environment: %s (ID: %s)', $this->colorize($environmentName), $environmentID)
        ]);

        $outputConfig = array_intersect_key($properties, array_fill_keys(['encrypted_sources', 'artifacts', 'platform'], 1));
        $this->outputTable($io, 'Agent configuration:', $outputConfig);
    }

    /**
     * @param IOInterface $io
     * @param Job $job
     * @param string $workspacePath
     *
     * @return bool
     */
    private function downloadArtifacts(IOInterface $io, Job $job, string $workspacePath)
    {
        $io->section($this->step(2));

        $build = $job->build();
        $storedArtifact = sprintf('%s-%s'), $build->type(), $build->id());

        $deploymentPath = $workspacePath . '/job';
        $artifactFile = $workspacePath . '/artifact.tgz';

        $io->listing([
            sprintf('Release Workspace: %s', $this->colorize($workspacePath)),

            sprintf('Artifact Repository: %s', $this->colorize('Filesystem')),
            sprintf('Repository Location: %s', $this->colorize($storedArtifact))
        ]);

        return ($this->artifacter)($deploymentPath, $artifactFile, $storedArtifact);
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

        $deploymentPath = $workspacePath . '/job';

        $config = ($this->reader)($deploymentPath, $defaultConfiguration);

        if (!$config) {
            return null;
        }

        $this->outputTable($io, 'Application configuration:', $config);

        return $config;
    }

    /**
     * @param IOInterface $io
     * @param Release $release
     * @param array $config
     * @param array $properties
     *
     * @return bool
     */
    private function transform(IOInterface $io, Release $release, array $config, array $properties)
    {
        $io->section($this->step(4));

        $platform = $config['platform'];
        $image = $config['image'];
        $steps = $config['build_transform'];

        $execution = new JobExecution($platform, 'build_transform', $config);

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

        return ($this->builder)($release, $io, $execution, $properties);
    }

    /**
     * @param IOInterface $io
     * @param Release $release
     * @param array $config
     * @param array $properties
     *
     * @return bool
     */
    private function beforeDeploy(IOInterface $io, Release $release, array $config, array $properties)
    {
        $io->section($this->step(5));

        $platform = $config['platform'];
        $image = $config['image'];
        $steps = $config['before_deploy'];

        $execution = new JobExecution($platform, 'before_deploy', $config);

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

        return ($this->builder)($release, $io, $execution, $properties);
    }

    /**
     * @param IOInterface $io
     * @param Release $release
     * @param string $platform
     * @param array $config
     * @param array $properties
     *
     * @return bool
     */
    private function deploy(IOInterface $io, Release $release, string $platform, array $config, array $properties)
    {
        $io->section($this->step(6));

        $execution = new JobExecution($platform, 'deploy', $config);

        $io->listing([
            sprintf('Platform: <info>%s</info>', $this->colorize($platform))
        ]);

        return ($this->deployer)($release, $io, $execution, $properties);
    }

    /**
     * @param IOInterface $io
     * @param Release $release
     * @param array $config
     * @param array $properties
     *
     * @return bool
     */
    private function afterDeploy(IOInterface $io, Release $release, array $config, array $properties)
    {
        $io->section($this->step(7));

        $platform = $config['platform'];
        $image = $config['image'];
        $steps = $config['after_deploy'];

        $execution = new JobExecution($platform, 'after_deploy', $config);

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

        return ($this->builder)($release, $io, $execution, $properties);
    }

    /**
     * @param string $status
     *
     * @return array
     */
    private function appendDeploymentStatus(array $config, $status)
    {
        if (!isset($config['env']['global'])) {
            $config['env']['global'] = [];
        }

        $config['env']['global'][self::DEPLOY_STATUS_VAR] = $status;

        return $config;
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
            $io->section('Release clean-up');

            $io->text('Release artifacts to remove:');
            $io->listing($this->colorize($artifacts));

            $io->newLine();

            ($this->cleaner)($artifacts);
        };

        $this->cleanup = $cleanup;
        if ($this->enableShutdownHandler) {
            // protip: avoid [$this, 'method'] notation, it makes searching and refactoring more difficult.
            register_shutdown_function(function () {
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
    private function deploymentFailure(IOInterface $io, $message)
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
    private function deploymentSuccess(IOInterface $io, $message)
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

        // If we got to this point and the status is still "Pushing", something terrible has happened.
        // The EventLogger will only fail deploys that aren't finished yet.
        $this->logger->failure();
    }
}
