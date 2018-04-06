<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\Rsync;

use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Deploy\PlatformTrait;
use Hal\Agent\Deploy\Rsync\Steps\Configurator;
use Hal\Agent\Deploy\Rsync\Steps\Verifier;
use Hal\Agent\Deploy\Rsync\Steps\CommandRunner;
use Hal\Agent\Deploy\Rsync\Steps\Deployer;
use Hal\Agent\JobExecution;
use Hal\Agent\JobPlatformInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\IOAwareInterface;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Release;

class RsyncDeployPlatform implements IOAwareInterface, JobPlatformInterface
{
    use FormatterTrait;
    use PlatformTrait;

    private const STEP_1_CONFIGURING = 'Rsync Platform - Validating configuration';
    private const STEP_2_VERIFYING = 'Rsync Platform - Verifying target directory is writeable';
    private const STEP_3_PRE_RUNNING = 'Rsync Platform - Running before deploy commands';
    private const STEP_4_DEPLOYING = 'Rsync Platform - Deploying code to server';
    private const STEP_5_POST_RUNNING = 'Rsync Platform - Running after deploy commands';

    private const ERR_INVALID_JOB = 'The provided job is an invalid type for this job platform';
    private const ERR_CONFIGURATOR = 'Rsync deploy platform is not configured correctly';
    private const ERR_VERIFIER = 'Could not verify target directory is writeable';
    private const ERR_PRE_RUNNER = 'Before deploy commands could not be ran successfully';
    private const ERR_DEPLOYER = 'Code could not be deployed to server';
    private const ERR_POST_RUNNER = 'After deploy commands could not be ran successfully';

    private const NOTE_NO_BEFORE_COMMANDS = 'Skipping before deploy commands: none found';
    private const NOTE_NO_AFTER_COMMANDS = 'Skipping after deploy commands: none found';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Configurator
     */
    private $configurator;

    /**
     * @var Verifier
     */
    private $verifier;

    /**
     * @var CommandRunner
     */
    private $commandRunner;

    /**
     * @var Deployer
     */
    private $deployer;

    /**
     * @param EventLogger $logger
     * @param Configurator $configurator
     * @param Verifier $verifier
     * @param CommandRunner $commandRunner
     * @param Deployer $deployer
     */
    public function __construct(
        EventLogger $logger,
        Configurator $configurator,
        Verifier $verifier,
        CommandRunner $commandRunner,
        Deployer $deployer
    ) {
        $this->logger = $logger;
        $this->configurator = $configurator;
        $this->verifier = $verifier;
        $this->commandRunner = $commandRunner;
        $this->deployer = $deployer;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(Job $job, JobExecution $execution, array $properties): bool
    {
        if (!$job instanceof Release) {
            $this->sendFailureEvent(self::ERR_INVALID_JOB);
            return false;
        }

        if (!$platformConfig = $this->configurator($job)) {
            $this->sendFailureEvent(self::ERR_CONFIGURATOR);
            return false;
        }

        if (!$this->verifier($platformConfig)) {
            $this->sendFailureEvent(self::ERR_VERIFIER);
            return false;
        }

        if (!$this->preRunner($execution, $platformConfig)) {
            $this->sendFailureEvent(self::ERR_PRE_RUNNER);
            return false;
        }

        if (!$this->deployer($execution, $platformConfig, $properties)) {
            $this->sendFailureEvent(self::ERR_DEPLOYER);
            return false;
        }

        if (!$this->postRunner($execution, $platformConfig)) {
            $this->sendFailureEvent(self::ERR_POST_RUNNER);
            return false;
        }

        return true;
    }

    /**
     * @param Job $job
     *
     * @return array|null
     */
    private function configurator(Job $job)
    {
        $this->getIO()->section(self::STEP_1_CONFIGURING);

        $platformConfig = ($this->configurator)($job);

        if (!$platformConfig) {
            return null;
        }

        $this->outputTable($this->getIO(), 'Platform configuration:', $platformConfig);

        return $platformConfig;
    }

    /**
     * @param array $config
     *
     * @return bool
     */
    private function verifier(array $config)
    {
        $this->getIO()->section(self::STEP_2_VERIFYING);

        return ($this->verifier)(
            $config['remoteUser'],
            $config['remoteServer'],
            $config['remotePath']
        );
    }

    /**
     * @param JobExecution $execution
     * @param array $config
     *
     * @return bool
     */
    private function preRunner(JobExecution $execution, array $config)
    {
        $this->getIO()->section(self::STEP_3_PRE_RUNNING);

        if (!$commands = $execution->parameter('rsync_before')) {
            $this->getIO()->note(self::NOTE_NO_BEFORE_COMMANDS);
            return true;
        }

        $this->outputTable($this->getIO(), 'Commands:', $commands);

        return ($this->commandRunner)(
            $config['remoteUser'],
            $config['remoteServer'],
            $config['remotePath'],
            $commands,
            $config['environmentVariables']
        );
    }

    /**
     * @param JobExecution $execution
     * @param array $config
     * @param array $properties
     *
     * @return bool
     */
    private function deployer(JobExecution $execution, array $config, array $properties)
    {
        $this->getIO()->section(self::STEP_4_DEPLOYING);

        $buildPath = $properties['workspace_path'] . '/job';
        $excludes = $execution->parameter('rsync_exclude') ?? [];

        return ($this->deployer)(
            $buildPath,
            $config['remoteUser'],
            $config['remoteServer'],
            $config['remotePath'],
            $excludes
        );
    }

    /**
     * @param JobExecution $execution
     * @param array $config
     *
     * @return bool
     */
    private function postRunner(JobExecution $execution, array $config)
    {
        $this->getIO()->section(self::STEP_5_POST_RUNNING);

        if (!$commands = $execution->parameter('rsync_after')) {
            $this->getIO()->note(self::NOTE_NO_AFTER_COMMANDS);
            return true;
        }

        $this->outputTable($this->getIO(), 'Commands:', $commands);

        return ($this->commandRunner)(
            $config['remoteUser'],
            $config['remoteServer'],
            $config['remotePath'],
            $commands,
            $config['environmentVariables']
        );
    }

    /**
     * @param string $message
     *
     * @return void
     */
    private function sendFailureEvent($message)
    {
        $this->logger->event('failure', $message);
        $this->getIO()->error($message);
    }
}
