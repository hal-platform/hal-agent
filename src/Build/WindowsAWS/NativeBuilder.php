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
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;
use Hal\Agent\Logger\EventLogger;

class NativeBuilder implements BuilderInterface
{
    // Comes with OutputAwareTrait
    use EmergencyBuildHandlerTrait;
    use InternalDebugLoggingTrait;

    const SECTION = 'AWS Windows';

    const EVENT_MESSAGE = 'Run build command %s';
    const EVENT_MESSAGE_CUSTOM = 'Run build command %s "%s"';
    const EVENT_AWS_CLEANUP = 'Clean up build server';

    const STATUS_CLI = 'Running build command [ <info>%s</info> ] in AWS';

    const ERR_PREPARE_FAILED = 'Failed to prepare build server';
    const ERR_MESSAGE_SKIPPING = 'Skipping %s remaining build commands';

    const SHORT_COMMAND_VALIDATION = '/^[\S\h]{1,50}$/';
    const TIMEOUT_INTERNAL_COMMAND = 120;

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var SSMCommandRunner
     */
    private $runner;

    /**
     * @var Powershellinator
     */
    private $powershell;

    /**
     * @var string
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param SSMCommandRunner $runner
     * @param Powershellinator $powershell
     * @param string $commandTimeout
     */
    public function __construct(EventLogger $logger, SSMCommandRunner $runner, Powershellinator $powershell, $commandTimeout)
    {
        $this->logger = $logger;
        $this->runner = $runner;
        $this->powershell = $powershell;
        $this->commandTimeout = (string) $commandTimeout;
    }

    /**
     * @inheritdoc
     */
    public function __invoke(SsmClient $ssm, $image, $instanceID, $buildID, array $commands, array $env)
    {
        // $image = throwaway

        $workDir = $this->powershell->getBaseBuildPath();

        $inputDir = "${workDir}\\${buildID}";
        $outputDir = "${workDir}\\${buildID}-output";
        $commands = $this->parseCommandsToScripts($buildID, $commands);

        // 1. Prepare instance to run build commands
        if (!$result = $this->prepare($ssm, $instanceID, $buildID, $inputDir, $commands, $env)) {
            return $this->bombout(false);
        }

        // 2. Enable cleanup failsafe
        $cleanup = $this->enableCleanup($ssm, $instanceID, $inputDir, $buildID);

        // 3. Run commands
        if (!$this->runCommands($ssm, $instanceID, $inputDir, $commands)) {
            return $this->bombout(false);
        }

        // 4. Transfer to build output
        if (!$this->transferToOutput($ssm, $instanceID, $inputDir, $outputDir)) {
            return $this->bombout(false);
        }

        // 5. Cleanup and clear cleanup/shutdown functionality
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
    private function prepare(SsmClient $ssm, $instanceID, $buildID, $inputDir, array $commandsWithScripts, $env)
    {
        $runner = $this->runner;
        $result = $runner($ssm, $instanceID, SSMCommandRunner::TYPE_POWERSHELL, [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('verifyBuildEnvironment', [
                    'inputDir' => $inputDir
                ]),
                $this->powershell->getScript('writeUserCommandsToBuildScripts', [
                    'buildID' => $buildID,
                    'commandsParsed' => $commandsWithScripts,
                ]),
                $this->powershell->getScript('writeEnvFile', [
                    'buildID' => $buildID,
                    'environment' => $env,
                ])
            ],
            'executionTimeout' => [(string) self::TIMEOUT_INTERNAL_COMMAND],
        ], [$this->isDebugLoggingEnabled()]);

        if (!$result) {
            $this->logger->event('failure', self::ERR_PREPARE_FAILED);
        }

        return $result;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $inputDir
     * @param array $commands
     *
     * @return bool
     */
    private function runCommands(SsmClient $ssm, $instanceID, $inputDir, array $commands)
    {
        $isSuccess = true;
        $runner = $this->runner;

        $total = count($commands);
        foreach ($commands as $num => $command) {
            $current = $num + 1;
            $remaining = $total - $current;

            $msg = $this->getEventMessage($command['command'], "[${current}/${total}]");

            $customContext = [
                'command' => $command['command'],
                'script' => $command['script'],
                'scriptFile' => $command['file']
            ];

            $result = $runner($ssm, $instanceID, SSMCommandRunner::TYPE_POWERSHELL, [
                'commands' => [
                    $this->powershell->getStandardPowershellHeader(),
                    $this->powershell->getScript('runBuildScriptNative', [
                        'buildScript' => $command['file']
                    ])
                ],
                'workingDirectory' => [$inputDir],
                'executionTimeout' => [$this->commandTimeout],
            ], [true, $msg, $customContext]);

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
     * @param string $outputDir
     *
     * @return bool
     */
    private function transferToOutput(SsmClient $ssm, $instanceID, $inputDir, $outputDir)
    {
        $runner = $this->runner;
        $result = $runner($ssm, $instanceID, SSMCommandRunner::TYPE_POWERSHELL, [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('transferBuildToOutput', [
                    'inputDir' => $inputDir,
                    'outputDir' => $outputDir
                ])
            ],
            'executionTimeout' => [(string) self::TIMEOUT_INTERNAL_COMMAND],
        ], [$this->isDebugLoggingEnabled()]);

        return $result;
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
            'executionTimeout' => [(string) self::TIMEOUT_INTERNAL_COMMAND],
        ], [$this->isDebugLoggingEnabled()]);

        return $result;
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
                    'envFile' => $this->powershell->getUserScriptFilePath($buildID, 'env')
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
        $this->enableEmergencyHandler($cleanup, self::EVENT_AWS_CLEANUP);

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
