<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\Ssm\SsmClient;
use Hal\Agent\Build\EmergencyBuildHandlerTrait;
use Hal\Agent\Build\InternalDebugLoggingTrait;
use Hal\Agent\Build\WindowsAWS\AWS\SSMCommandRunner;
use Hal\Agent\Build\WindowsAWS\Utility\Dockerinator;
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;
use Hal\Agent\Docker\DockerImageValidator;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\IOAwareTrait;

class DockerBuilder implements BuilderInterface
{
    // Comes with OutputAwareTrait
    use EmergencyBuildHandlerTrait;
    use InternalDebugLoggingTrait;
    use IOAwareTrait;

    /**
     * @var string
     */
    const SECTION = 'AWS Windows Docker';

    const EVENT_MESSAGE = 'Run build command %s';
    const EVENT_MESSAGE_CUSTOM = 'Run build command %s "%s"';

    const EVENT_STARTING_CONTAINER = 'Starting Docker container';
    const EVENT_START_CONTAINER_STARTED = 'Docker container "%s" started';
    const EVENT_AWS_DOCKER_CLEANUP = 'Clean up docker build server';

    const STATUS_CLI = 'Running build command [ <info>%s</info> ] in AWS Docker container';

    const ERR_PREPARE_FAILED = 'Failed to prepare build server';
    const ERR_MESSAGE_SKIPPING = 'Skipping %s remaining build commands';

    const SHORT_COMMAND_VALIDATION = '/^[\S\h]{1,50}$/';
    const DEFAULT_TIMEOUT_INTERNAL_COMMAND = 120;
    const DOCKER_PREFIX = 'windocker:';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var SSMCommandRunner
     */
    private $runner;

    /**
     * @var Dockerinator
     */
    private $docker;

    /**
     * @var Powershellinator
     */
    private $powershell;

    /**
     * @var DockerImageValidator
     */
    private $validator;

    /**
     * @var int
     */
    private $internalTimeout;

    /**
     * @param EventLogger $logger
     * @param SSMCommandRunner $runner
     * @param Dockerinator $docker
     * @param Powershellinator $powershell
     * @param DockerImageValidator $validator
     */
    public function __construct(
        EventLogger $logger,
        SSMCommandRunner $runner,
        Dockerinator $docker,
        Powershellinator $powershell,
        DockerImageValidator $validator
    ) {
        $this->logger = $logger;
        $this->runner = $runner;
        $this->docker = $docker;
        $this->powershell = $powershell;
        $this->validator = $validator;

        $this->internalTimeout = self::DEFAULT_TIMEOUT_INTERNAL_COMMAND;
    }

    /**
     * @param int $seconds
     *
     * @return void
     */
    public function setInternalCommandTimeout($seconds)
    {
        $this->internalTimeout = (int) $seconds;
    }

    /**
     * @inheritdoc
     */
    public function __invoke(string $jobID, $image, SsmClient $ssm, $instanceID, array $commands, array $env): bool
    {
        $workDir = $this->powershell->getBaseBuildPath();

        $inputDir = "${workDir}\\${jobID}";
        $outputDir = "${workDir}\\${jobID}-output";
        $commands = $this->parseCommandsToScripts($jobID, $commands);

        if (!$dockerImage = $this->validator->validate($image)) {
            return $this->bombout(false);
        }

        // 1. Prepare instance to run build commands
        if (!$result = $this->prepare($ssm, $instanceID, $jobID, $inputDir, $commands, $env)) {
            return $this->bombout(false);
        }

        // 2. Enable cleanup failsafe
        $cleanup = $this->enableCleanup($ssm, $instanceID, $inputDir, $jobID);

        // 3. Build container
        $containerName = strtolower(str_replace('.', '', $jobID));

        // 4. Create container
        if (!$this->docker->createContainer($ssm, $instanceID, $dockerImage, $containerName)) {
            return $this->bombout(false);
        }

        $this->status(self::EVENT_STARTING_CONTAINER, self::SECTION);

        // 5. Enable docker cleanup failsafe
        $cleanup = $this->enableDockerCleanup($ssm, $instanceID, $containerName, $cleanup);

        // 6. Copy into container
        if (!$this->docker->copyIntoContainer($ssm, $instanceID, $jobID, $containerName, $inputDir)) {
            return $this->bombout(false);
        }

        // 7. Start container
        if (!$this->docker->startContainer($ssm, $instanceID, $containerName)) {
            return $this->bombout(false);
        }

        $this->status(sprintf(self::EVENT_START_CONTAINER_STARTED, $containerName), self::SECTION);

        // 8. Run commands
        if (!$this->runCommands($ssm, $instanceID, $containerName, $commands)) {
            return $this->bombout(false);
        }

        // 9. Copy out of container
        if (!$this->docker->copyFromContainer($ssm, $instanceID, $containerName, $outputDir)) {
            return $this->bombout(false);
        }

        // 10. Cleanup and clear cleanup/shutdown functionality
        $this->runCleanup($cleanup);

        return $this->bombout(true);
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $buildID
     * @param string $inputDir
     * @param array $commandsWithScripts
     * @param array $env
     *
     * @return bool
     */
    private function prepare(SsmClient $ssm, $instanceID, $buildID, $inputDir, array $commandsWithScripts, array $env)
    {
        // We use a custom log context, so we dont mistakenly output secrets in the logs
        $logContext = [
            'instanceID' => $instanceID,
            'commandType' => SSMCommandRunner::TYPE_POWERSHELL
        ];

        $runner = $this->runner;
        $result = $runner($ssm, $instanceID, SSMCommandRunner::TYPE_POWERSHELL, [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('verifyBuildEnvironment', [
                    'inputDir' => $inputDir
                ]),
                $this->powershell->getScript('loginDocker'),
                $this->powershell->getScript('writeUserCommandsToBuildScripts', [
                    'buildID' => $buildID,
                    'commandsParsed' => $commandsWithScripts,
                ]),
                $this->powershell->getScript('writeEnvFile', [
                    'buildID' => $buildID,
                    'environment' => $env,
                ])
            ],
            'executionTimeout' => [(string) $this->internalTimeout],
        ], [$this->isDebugLoggingEnabled(), SSMCommandRunner::EVENT_MESSAGE, $logContext]);

        if (!$result) {
            $this->logger->event('failure', self::ERR_PREPARE_FAILED);
        }

        return $result;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @param string $containerName
     * @param array $commands
     *
     * @return bool
     */
    private function runCommands(SsmClient $ssm, $instanceID, $containerName, array $commands)
    {
        $isSuccess = true;

        $total = count($commands);
        foreach ($commands as $num => $command) {
            $current = $num + 1;
            $remaining = $total - $current;

            $msg = $this->getEventMessage($command['command'], "[${current}/${total}]");
            $result = $this->docker->runCommand($ssm, $instanceID, $containerName, $command, $msg);
            if (!$result) {
                if ($remaining > 0) {
                    $this->logSkippedCommands($remaining);
                }

                $isSuccess = false;
                break;
            }
        }

        return $isSuccess;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $inputDir
     * @param string $buildID
     *
     * @return bool
     */
    private function cleanupBuilder(SsmClient $ssm, $instanceID, $inputDir, $buildID)
    {
        $runner = $this->runner;
        $result = $runner($ssm, $instanceID, SSMCommandRunner::TYPE_POWERSHELL, [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('cleanupAfterBuild', [
                    'buildID' => $buildID,
                    'inputDir' => $inputDir
                ])
            ],
            'executionTimeout' => [(string) $this->internalTimeout],
        ], [$this->isDebugLoggingEnabled()]);

        return $result;
    }

    /**
     * @param string $containerName
     *
     * @return void
     */
    private function cleanupContainer(SsmClient $ssm, $instanceID, $containerName)
    {
        $this->status(sprintf('Cleaning up container "%s"', $containerName), self::SECTION);

        $this->docker->cleanupContainer($ssm, $instanceID, $containerName);
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
            $msg = sprintf(static::EVENT_MESSAGE_CUSTOM, $count, $clean);
            $msgCLI = "${count} ${clean}";
        }

        $this->status(sprintf(static::STATUS_CLI, $msgCLI), static::SECTION);
        return $msg;
    }

    /**
     * @param string $buildID
     * @param array $commands
     *
     * @return array
     */
    private function parseCommandsToScripts($buildID, array $commands)
    {
        $commandScripts = [];

        foreach ($commands as $num => $command) {
            $commandScripts[] = [
                'command' => $command,
                'script' => $this->powershell->getScript('getBuildScript', [
                    'command' => $command,
                    'envFile' => $this->powershell->getUserScriptFilePathForContainer($buildID, 'env')
                ]),
                'file' => $this->powershell->getUserScriptFilePath($buildID, $num),
                'container_file' => $this->powershell->getUserScriptFilePathForContainer($buildID, $num)
            ];
        }

        return $commandScripts;
    }

    /**
     * @param int $remainingCommands
     *
     * @return void
     */
    private function logSkippedCommands($remainingCommands)
    {
        $msg = sprintf(self::ERR_MESSAGE_SKIPPING, $remainingCommands);

        $this->logger->event('info', $msg);

        $this->status('<error>Build command failed</error>', static::SECTION);
        $this->status($msg, static::SECTION);
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $inputDir
     * @param string $buildID
     *
     * @return callable
     */
    private function enableCleanup(SsmClient $ssm, $instanceID, $inputDir, $buildID)
    {
        $cleanup = function () use ($ssm, $instanceID, $inputDir, $buildID) {
            $this->cleanupBuilder($ssm, $instanceID, $inputDir, $buildID);
        };

        // Set emergency handler in case of super fatal
        $this->enableEmergencyHandler($cleanup, self::EVENT_AWS_DOCKER_CLEANUP);

        return $cleanup;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @param string $containerName
     * @param callable $builderCleanup
     *
     * @return callable
     */
    private function enableDockerCleanup(SsmClient $ssm, $instanceID, $containerName, callable $builderCleanup)
    {
        $cleanup = function () use ($ssm, $instanceID, $containerName, $builderCleanup) {
            $this->cleanupContainer($ssm, $instanceID, $containerName);
            $builderCleanup();
        };

        // Set emergency handler in case of super fatal
        $this->enableEmergencyHandler($cleanup, self::EVENT_AWS_DOCKER_CLEANUP);

        return $cleanup;
    }

    /**
     * @param callable $cleanup
     *
     * @return void
     */
    private function runCleanup(callable $cleanup)
    {
        $cleanup();
        $this->cleanup(null);
    }
}
