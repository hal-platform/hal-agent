<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\Ssm\SsmClient;
use Hal\Agent\Build\InternalDebugLoggingTrait;
use Hal\Agent\Build\WindowsAWS\AWS\SSMCommandRunner;
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\IOAwareTrait;

class NativeBuilder implements BuilderInterface
{
    use InternalDebugLoggingTrait;
    use IOAwareTrait;

    private const EVENT_MESSAGE = 'Build step %s';
    private const EVENT_MESSAGE_CUSTOM = 'Build step %s "%s"';

    private const ACTION_PREPARING_INSTANCE = 'Preparing AWS instance for job';

    private const STATUS_CLI = 'Running build step [ <info>%s</info> ] in Windows AWS';

    private const ERR_PREPARE_FAILED = 'Failed to prepare builder';
    private const ERR_MESSAGE_SKIPPING = 'Skipping %s remaining build steps';

    private const SHORT_COMMAND_VALIDATION = '/^[\S\h]{1,80}$/';

    private const DEFAULT_TIMEOUT_INTERNAL_COMMAND = 120;

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
    private $internalTimeout;
    private $buildStepTimeout;

    /**
     * @param EventLogger $logger
     * @param SSMCommandRunner $runner
     * @param Powershellinator $powershell
     * @param string $buildStepTimeout
     */
    public function __construct(
        EventLogger $logger,
        SSMCommandRunner $runner,
        Powershellinator $powershell,
        string $buildStepTimeout
    ) {
        $this->logger = $logger;
        $this->runner = $runner;
        $this->powershell = $powershell;

        $this->buildStepTimeout = $buildStepTimeout;
        $this->setInternalCommandTimeout(self::DEFAULT_TIMEOUT_INTERNAL_COMMAND);
    }

    /**
     * @param int $seconds
     *
     * @return void
     */
    public function setInternalCommandTimeout(int $seconds)
    {
        // yep this is weird. AWS SDK requires this to be string.
        $this->internalTimeout = (string) $seconds;
    }

    /**
     * @inheritdoc
     */
    public function __invoke(string $jobID, $image, SsmClient $ssm, $instanceID, array $commands, array $env): bool
    {
        // $image = throwaway

        $this->getIO()->note(self::ACTION_PREPARING_INSTANCE);

        $workDir = $this->powershell->getBaseBuildPath();
        $inputDir = "${workDir}\\${jobID}";
        $outputDir = "${workDir}\\${jobID}-output";

        $scriptedSteps = $this->parseCommandsToScripts($jobID, $commands);

        // 1. Prepare instance to run build commands
        if (!$result = $this->prepare($ssm, $instanceID, $jobID, $inputDir, $scriptedSteps, $env)) {
            return false;
        }

        $total = count($scriptedSteps);
        $current = 0;

        // 2. Run steps
        foreach ($scriptedSteps as $step) {
            $current++;

            if (!$result = $this->runStep($ssm, $instanceID, $inputDir, $step, $current, $total)) {
                return false;
            }
        }

        // 3. Transfer to build output
        if (!$this->transferToOutput($ssm, $instanceID, $inputDir, $outputDir)) {
            return false;
        }

        return true;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $jobID
     * @param string $inputDir
     * @param array $commandsWithScripts
     * @param array $env
     *
     * @return bool
     */
    private function prepare(SsmClient $ssm, $instanceID, $jobID, $inputDir, array $commandsWithScripts, $env)
    {
        // We use a custom log context, so we dont mistakenly output secrets in the logs
        $logContext = [
            'instanceID' => $instanceID,
            'commandType' => SSMCommandRunner::TYPE_POWERSHELL
        ];

        $config = [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('verifyBuildEnvironment', [
                    'inputDir' => $inputDir
                ]),
                $this->powershell->getScript('writeUserCommandsToBuildScripts', [
                    'buildID' => $jobID,
                    'commandsParsed' => $commandsWithScripts,
                ]),
                $this->powershell->getScript('writeEnvFile', [
                    'buildID' => $jobID,
                    'environment' => $env,
                ])
            ],
            'executionTimeout' => [$this->internalTimeout],
        ];

        $result = ($this->runner)(
            $ssm,
            $instanceID,
            SSMCommandRunner::TYPE_POWERSHELL,
            $config,
            [$this->isDebugLoggingEnabled(), SSMCommandRunner::EVENT_MESSAGE, $logContext]
        );

        if (!$result) {
            $this->logger->event('failure', self::ERR_PREPARE_FAILED);
        }

        return $result;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $inputDir
     * @param array $script
     * @param array $currentStep
     * @param array $totalSteps
     *
     * @return bool
     */
    private function runStep(SsmClient $ssm, $instanceID, $inputDir, array $script, $currentStep, $totalSteps)
    {
        $remaining = $totalSteps - $currentStep;

        $msg = $this->getEventMessage($script['command'], "[${currentStep}/${totalSteps}]");

        $customContext = [
            'command' => $script['command'],
            'script' => $script['script'],
            'scriptFile' => $script['file']
        ];

        $config = [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('runBuildScriptNative', [
                    'buildScript' => $script['file']
                ])
            ],
            'workingDirectory' => [$inputDir],
            'executionTimeout' => [$this->buildStepTimeout],
        ];

        $result = ($this->runner)(
            $ssm,
            $instanceID,
            SSMCommandRunner::TYPE_POWERSHELL,
            $config,
            [true, $msg, $customContext]
        );

        if (!$result) {
            if ($remaining > 0) {
                $this->logSkippedCommands($remaining);
            }

            return false;
        }

        return true;
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
        $config = [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('transferBuildToOutput', [
                    'inputDir' => $inputDir,
                    'outputDir' => $outputDir
                ])
            ],
            'executionTimeout' => [$this->internalTimeout],
        ];

        return ($this->runner)(
            $ssm,
            $instanceID,
            SSMCommandRunner::TYPE_POWERSHELL,
            $config,
            [$this->isDebugLoggingEnabled()]
        );
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

        $this->getIO()->listing([sprintf(static::STATUS_CLI, $msgCLI)]);

        return $msg;
    }

    /**
     * @param string $jobID
     * @param array $steps
     *
     * @return array
     */
    private function parseCommandsToScripts($jobID, array $steps)
    {
        $scriptedSteps = [];

        foreach ($steps as $stepNum => $step) {
            $scriptedSteps[] = [
                'command' => $step,
                'script' => $this->powershell->getScript('getBuildScript', [
                    'command' => $step,
                    'envFile' => $this->powershell->getUserScriptFilePath($jobID, 'env')
                ]),
                'file' => $this->powershell->getUserScriptFilePath($jobID, $stepNum)
            ];
        }

        return $scriptedSteps;
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

        $this->getIO()->warning('Build step failed.');
        $this->getIO()->text($msg);
    }
}
