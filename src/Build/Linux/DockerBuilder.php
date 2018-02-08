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
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\IOAwareTrait;

class DockerBuilder implements BuilderInterface
{
    use EmergencyBuildHandlerTrait;
    use IOAwareTrait;

    const EVENT_MESSAGE = 'Run build step %s';
    const EVENT_MESSAGE_CUSTOM = 'Run build step %s "%s"';

    const EVENT_STARTING_CONTAINER = 'Starting Docker container';
    const EVENT_START_CONTAINER_STARTED = 'Docker container "%s" started';
    const EVENT_DOCKER_CLEANUP = 'Cleaning up container "%s"';

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
     * @param EventLogger $logger
     * @param LinuxDockerinator $docker
     * @param DockerImageValidator $validator
     */
    public function __construct(
        EventLogger $logger,
        LinuxDockerinator $docker,
        DockerImageValidator $validator
    ) {
        $this->logger = $logger;
        $this->docker = $docker;
        $this->validator = $validator;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(string $jobID, string $image, string $remoteConnection, string $remoteFile, array $commands, array $env): bool
    {
        if (!$dockerImage = $this->validator->validate($image)) {
            return $this->bombout(false);
        }

        $containerName = strtolower($jobID);
        $imagedCommands = $this->organizeCommands($dockerImage, $commands);

        $total = count($commands);
        $current = 0;

        foreach ($imagedCommands as $entry) {
            list($image, $commands) = $entry;

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

            // 5. Run commands
            foreach ($commands as $command) {
                $current++;

                if (!$result = $this->runCommand($remoteConnection, $containerName, $command, $current, $total)) {
                    return $this->bombout(false);
                }
            }

            // 6. Copy out of container
            if (!$this->docker->copyFromContainer($remoteConnection, $containerName, $remoteFile)) {
                return $this->bombout(false);
            }

            // 7. Run and clear docker cleanup/shutdown functionality
            $this->runDockerCleanup($cleanup);
        }

        return $this->bombout(true);
    }

    /**
     * Organize a list of commands into an array such as
     * [
     *     [ $image1, [$command1, $command2] ]
     *     [ $image2, [$command3] ]
     *     [ $image1, [$command4] ]
     * ]
     *
     * @param string $defaultImageName
     * @param array $commands
     *
     * @return array
     */
    private function organizeCommands($defaultImageName, array $commands)
    {
        $organized = [];
        $prevImage = null;
        foreach ($commands as $command) {
            list($image, $command) = $this->parseCommand($defaultImageName, $command);

            // Using same image in a row, rebuild the entire entry with the added command
            if ($image === $prevImage) {
                list($i, $cmds) = array_pop($organized);
                $cmds[] = $command;

                $entry = [$image, $cmds];

            } else {
                $entry = [$image, [$command]];
            }

            $organized[] = $entry;

            $prevImage = $image;
        }

        return $organized;
    }

    /**
     * This should return the docker image to use (WITHOUT "docker:" prefix), and command without docker instructions.
     *
     * @param string $defaultImage
     * @param string $command
     *
     * @return array [$imageName, $command]
     */
    private function parseCommand($defaultImage, $command)
    {
        // if (preg_match(self::$dockerPatternRegex, $command, $matches)) {
        //     $image = array_shift($matches);

        //     // Remove docker prefix from command
        //     $command = substr($command, strlen($image));

        //     // return docker image as just the "docker/*" part
        //     $image = substr($image, strlen(self::DOCKER_PREFIX));

        //     return [trim($image), trim($command)];
        // }

        return [$defaultImage, $command];
    }

    /**
     * @param string $remoteConnection
     * @param string $containerName
     * @param string $command
     * @param int $currentStep
     * @param int $totalSteps
     *
     * @return bool
     */
    private function runCommand($remoteConnection, $containerName, $command, $currentStep, $totalSteps): bool
    {
        $remaining = $totalSteps - $currentStep;

        $msg = $this->getEventMessage($command, "[${currentStep}/${totalSteps}]");
        if (!$result = $this->docker->runCommand($remoteConnection, $containerName, $command, $msg)) {
            if ($remaining > 0) {
                $this->logSkippedCommands($remaining);
            }

            return false;
        }

        // all good
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
