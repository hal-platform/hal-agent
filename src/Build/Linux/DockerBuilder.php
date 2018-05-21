<?php
/**
 * @copyright (c) 2018 Steve Kluck
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux;

use Hal\Agent\Build\EmergencyBuildHandlerTrait;
use Hal\Agent\Docker\DockerImageValidator;
use Hal\Agent\Docker\LinuxDockerinator;
use Hal\Agent\Job\FileCompression;
use Hal\Agent\JobConfiguration\StepParser;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\IOAwareTrait;
use QL\MCP\Common\GUID;

class DockerBuilder implements BuilderInterface
{
    use EmergencyBuildHandlerTrait;
    use IOAwareTrait;

    const EVENT_MESSAGE = 'Build step %s';
    const EVENT_MESSAGE_CUSTOM = 'Build step %s "%s"';

    const EVENT_STARTING_CONTAINER = 'Starting Docker container';
    const EVENT_START_CONTAINER_STARTED = 'Docker container "%s" started';
    const EVENT_DOCKER_CONTAINER_CLEANUP = 'Cleaning up Docker container "%s"';
    const EVENT_DOCKER_VOLUME_CLEANUP = 'Cleaning up Docker volume "%s"';

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
     * @var FileCompression
     */
    private $compression;

    /**
     * @var StepParser
     */
    private $steps;

    /**
     * @param EventLogger $logger
     * @param LinuxDockerinator $docker
     * @param DockerImageValidator $validator
     * @param FileCompression $compression
     * @param StepParser $steps
     */
    public function __construct(
        EventLogger $logger,
        LinuxDockerinator $docker,
        DockerImageValidator $validator,
        FileCompression $compression,
        StepParser $steps
    ) {
        $this->logger = $logger;
        $this->docker = $docker;
        $this->validator = $validator;
        $this->compression = $compression;
        $this->steps = $steps;
    }

    /**
     * @param string $jobID
     * @param string $image
     *
     * @param string $workspacePath
     * @param string $stagePath
     * @param array $steps
     * @param array $env
     *
     * @return bool
     */
    public function __invoke(string $jobID, string $image, string $workspacePath, string $stagePath, array $steps, array $env): bool
    {
        if (!$stageBundleFile = $this->bundleStagePath($workspacePath, $stagePath)) {
            return $this->bombout(false);
        }

        if (!$dockerImage = $this->validator->validate($image)) {
            return $this->bombout(false);
        }

        // 1. Parse jobs from steps (a job in this case is a single container which can run multiple steps)
        // @todo move this up a level into the build platform

        $stages = $this->steps->organizeCommandsIntoJobs($dockerImage, $steps);
        foreach ($stages as $stage) {
            [$image, $steps] = $stage;

            if (!$this->runStage($image, $steps, $jobID, $stageBundleFile, $env)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $image
     * @param array $steps
     *
     * @param string $stageID
     * @param string $stageBundleFile
     * @param array $env
     *
     * @return bool
     */
    private function runStage(
        string $image,
        array $steps,
        string $stageID,
        string $stageBundleFile,
        array $env
    ) {
        $containerName = strtolower($stageID);
        $volumeName = "${containerName}-workspace";

        $total = count($steps);
        $current = 0;

        // 1. Create volume to share between workspaces
        if (!$this->docker->createVolume($volumeName)) {
            return $this->bombout(false);
        }

        // 2. Create initialization container
        if (!$this->docker->createContainer($image, $containerName, $volumeName)) {
            return $this->bombout(false);
        }

        $this->getIO()->note(self::EVENT_STARTING_CONTAINER);

        // 3. Enable cleanup failsafe
        $cleanup = $this->enableDockerCleanup($containerName, $volumeName);

        // 4. Copy into initialization container
        if (!$this->docker->copyIntoContainer($containerName, $stageBundleFile)) {
            return $this->bombout(false);
        }

        // Clean up our initialization container
        $this->cleanupContainer($containerName);

        $this->getIO()->note(sprintf(self::EVENT_START_CONTAINER_STARTED, $containerName));

        // 5. Run commands
        foreach ($steps as $step) {
            $current++;

            if (!$this->docker->createContainer($image, $containerName, $volumeName, $env, $step)) {
                return $this->bombout(false);
            }

            if (!$result = $this->runStep($containerName, $step, $current, $total)) {
                return $this->bombout(false);
            }

            $this->cleanupContainer($containerName);
        }

        // 6. Create shutdown container
        if (!$this->docker->createContainer($image, $containerName, $volumeName)) {
            return $this->bombout(false);
        }

        // 7. Copy out of container
        if (!$this->docker->copyFromContainer($containerName, $stageBundleFile)) {
            return $this->bombout(false);
        }

        // 8. Run and clear docker cleanup/shutdown functionality
        $this->runDockerCleanup($cleanup);

        return $this->bombout(true);
    }

    /**
     * @param string $workspacePath
     * @param string $stagePath
     *
     * @return string|null
     */
    private function bundleStagePath($workspacePath, $stagePath)
    {
        $random = GUID::create()->format(GUID::HYPHENATED);
        $stageBundleFile = "${workspacePath}/${random}.tgz";

        if (!$this->compression->packTarArchive($stagePath, $stageBundleFile)) {
            return null;
        }

        return $stageBundleFile;
    }

    /**
     * @param string $containerName
     * @param string $step
     * @param int $currentStep
     * @param int $totalSteps
     *
     * @return bool
     */
    private function runStep($containerName, $step, $currentStep, $totalSteps)
    {
        $remaining = $totalSteps - $currentStep;

        $msg = $this->getEventMessage($step, "[${currentStep}/${totalSteps}]");

        if (!$result = $this->docker->startUserContainer($containerName, $step, $msg)) {
            if ($remaining > 0) {
                $this->logSkippedCommands($remaining);
            }

            return false;
        }

        return true;
    }

    /**
     * @param string $containerName
     *
     * @return void
     */
    private function cleanupContainer($containerName)
    {
        $this->getIO()->note(sprintf(self::EVENT_DOCKER_CONTAINER_CLEANUP, $containerName));

        $this->docker->cleanupContainer($containerName);
    }

    /**
     * @param string $volumeName
     *
     * @return void
     */
    private function cleanupVolume($volumeName)
    {
        $this->getIO()->note(sprintf(self::EVENT_DOCKER_VOLUME_CLEANUP, $volumeName));

        $this->docker->cleanupVolume($volumeName);
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
     * @param string $count
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
     * @param string $containerName
     * @param string $volumeName
     *
     * @return callable
     */
    private function enableDockerCleanup($containerName, $volumeName)
    {
        $cleanup = function () use ($containerName, $volumeName) {
            $this->cleanupContainer($containerName);
            $this->cleanupVolume($volumeName);
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
