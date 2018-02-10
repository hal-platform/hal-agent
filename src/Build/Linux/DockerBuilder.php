<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux;

use Hal\Agent\Build\EmergencyBuildHandlerTrait;
use Hal\Agent\Docker\DockerImageValidator;
use Hal\Agent\Docker\LinuxDockerinator;
use Hal\Agent\JobConfiguration\StepParser;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\IOAwareTrait;

class DockerBuilder implements BuilderInterface
{
    use EmergencyBuildHandlerTrait;
    use IOAwareTrait;

    const EVENT_MESSAGE = 'Build step %s';
    const EVENT_MESSAGE_CUSTOM = 'Build step %s "%s"';

    const EVENT_STARTING_CONTAINER = 'Starting Docker container';
    const EVENT_START_CONTAINER_STARTED = 'Docker container "%s" started';
    const EVENT_DOCKER_CLEANUP = 'Cleaning up Docker container "%s"';

    const STATUS_CLI = 'Running build step [ <info>%s</info> ] in Linux Docker container';

    const ERR_MESSAGE_SKIPPING = 'Skipping %s remaining build steps';

    const SHORT_COMMAND_VALIDATION = '/^[\S\h]{1,80}$/';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var LinuxDockerinator
     */
    private $docker;

    /**
     * @var DockerImageValidator
     */
    private $validator;

    /**
     * @var StepParser
     */
    private $steps;

    /**
     * @param EventLogger $logger
     * @param LinuxDockerinator $docker
     * @param DockerImageValidator $validator
     * @param StepParser $steps
     */
    public function __construct(
        EventLogger $logger,
        LinuxDockerinator $docker,
        DockerImageValidator $validator,
        StepParser $steps
    ) {
        $this->logger = $logger;
        $this->docker = $docker;
        $this->validator = $validator;
        $this->steps = $steps;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(string $jobID, string $image, string $remoteConnection, string $remoteFile, array $steps, array $env): bool
    {
        if (!$dockerImage = $this->validator->validate($image)) {
            return $this->bombout(false);
        }

        // 1. Parse jobs from steps (a job in this case is a single container which can run multiple steps)
        $containerName = strtolower($jobID);
        $jobs = $this->steps->organizeCommandsIntoJobs($dockerImage, $steps);

        $total = count($steps);
        $current = 0;

        foreach ($jobs as $job) {
            [$image, $steps] = $job;

            // 2. Create container
            if (!$this->docker->createContainer($remoteConnection, $image, $containerName, $env)) {
                return $this->bombout(false);
            }

            $this->getIO()->note(self::EVENT_STARTING_CONTAINER);

            // 3. Enable cleanup failsafe
            $cleanup = $this->enableDockerCleanup($remoteConnection, $containerName);

            // 4. Copy into container
            if (!$this->docker->copyIntoContainer($remoteConnection, $jobID, $containerName, $remoteFile)) {
                return $this->bombout(false);
            }

            // 5. Start container
            if (!$this->docker->startContainer($remoteConnection, $containerName)) {
                return $this->bombout(false);
            }

            $this->getIO()->note(sprintf(self::EVENT_START_CONTAINER_STARTED, $containerName));

            // 6. Run commands
            foreach ($steps as $step) {
                $current++;

                if (!$result = $this->runStep($remoteConnection, $containerName, $step, $current, $total)) {
                    return $this->bombout(false);
                }
            }

            // 7. Copy out of container
            if (!$this->docker->copyFromContainer($remoteConnection, $containerName, $remoteFile)) {
                return $this->bombout(false);
            }

            // 8. Run and clear docker cleanup/shutdown functionality
            $this->runDockerCleanup($cleanup);
        }

        return $this->bombout(true);
    }

    /**
     * @param string $remoteConnection
     * @param string $containerName
     * @param string $step
     * @param int $currentStep
     * @param int $totalSteps
     *
     * @return bool
     */
    private function runStep($remoteConnection, $containerName, $step, $currentStep, $totalSteps)
    {
        $remaining = $totalSteps - $currentStep;

        $msg = $this->getEventMessage($step, "[${currentStep}/${totalSteps}]");
        if (!$result = $this->docker->runCommand($remoteConnection, $containerName, $step, $msg)) {
            if ($remaining > 0) {
                $this->logSkippedCommands($remaining);
            }

            return false;
        }

        return true;
    }

    /**
     * @param string $remoteConnection
     * @param string $containerName
     *
     * @return void
     */
    private function cleanupContainer($remoteConnection, $containerName)
    {
        $this->getIO()->note(sprintf(self::EVENT_DOCKER_CLEANUP, $containerName));

        $this->docker->cleanupContainer($remoteConnection, $containerName);
    }

    /**
     * @param int $remainingCommands
     */
    private function logSkippedCommands($remainingCommands)
    {
        $msg = sprintf(static::ERR_MESSAGE_SKIPPING, $remainingCommands);

        $this->logger->event('info', $msg);

        $this->getIO()->warning('Build step failed.');
        $this->getIO()->text($msg);
    }

    /**
     * @param string $command
     * @param int $count
     *
     * @return string
     */
    private function getEventMessage($command, $count)
    {
        $msg = sprintf(static::EVENT_MESSAGE, $count);
        $msgCLI = "${count} Command";

        if (1 === preg_match(self::SHORT_COMMAND_VALIDATION, $command)) {
            $clean = str_replace(["\n", "\t"], " ", $command);
            $msg = sprintf(static::EVENT_MESSAGE_CUSTOM, $count, $command);
            $msgCLI = "${count} ${clean}";
        }

        $this->getIO()->listing([sprintf(static::STATUS_CLI, $msgCLI)]);

        return $msg;
    }

    /**
     * @param string $remoteConnection
     * @param string $containerName
     *
     * @return callable
     */
    private function enableDockerCleanup($remoteConnection, $containerName)
    {
        $cleanup = function () use ($remoteConnection, $containerName) {
            $this->cleanupContainer($remoteConnection, $containerName);
        };

        // Set emergency handler in case of super fatal
        $this->enableEmergencyHandler($cleanup);

        return $cleanup;
    }

    /**
     * @param callable $cleanup
     *
     * @return callable
     */
    private function runDockerCleanup(callable $cleanup)
    {
        $cleanup();
        $this->cleanup(null);
    }
}
