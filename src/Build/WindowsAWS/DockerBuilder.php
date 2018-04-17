<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\Ssm\SsmClient;
use Hal\Agent\Build\EmergencyBuildHandlerTrait;
use Hal\Agent\Build\InternalDebugLoggingTrait;
use Hal\Agent\Build\WindowsAWS\AWS\SSMCommandRunner;
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;
use Hal\Agent\Docker\DockerImageValidator;
use Hal\Agent\Docker\WindowsSSMDockerinator;
use Hal\Agent\JobConfiguration\StepParser;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\IOAwareTrait;

class DockerBuilder implements BuilderInterface
{
    use EmergencyBuildHandlerTrait;
    use InternalDebugLoggingTrait;
    use IOAwareTrait;

    private const EVENT_MESSAGE = 'Build step %s';
    private const EVENT_MESSAGE_CUSTOM = 'Build step %s "%s"';

    private const ACTION_PREPARING_INSTANCE = 'Preparing AWS instance for job';
    private const ACTION_STARTING_CONTAINER = 'Starting Docker container';
    private const ACTION_START_CONTAINER_STARTED = 'Docker container "%s" started';
    private const ACTION_DOCKER_CLEANUP = 'Cleaning up Docker container "%s"';

    private const STATUS_CLI = 'Running build step [ <info>%s</info> ] in Windows AWS Docker container';

    private const ERR_PREPARE_FAILED = 'Failed to prepare builder';
    private const ERR_SHIFT_FAILED = 'Failed to shift workspace for next container';
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
     * @var WindowsSSMDockerinator
     */
    private $docker;

    /**
     * @var DockerImageValidator
     */
    private $validator;

    /**
     * @var Powershellinator
     */
    private $powershell;

    /**
     * @var StepParser
     */
    private $steps;

    /**
     * @var string
     */
    private $internalTimeout;

    /**
     * @param EventLogger $logger
     * @param SSMCommandRunner $runner
     * @param WindowsSSMDockerinator $docker
     * @param Powershellinator $powershell
     * @param DockerImageValidator $validator
     * @param StepParser $steps
     */
    public function __construct(
        EventLogger $logger,
        SSMCommandRunner $runner,
        WindowsSSMDockerinator $docker,
        DockerImageValidator $validator,
        Powershellinator $powershell,
        StepParser $steps
    ) {
        $this->logger = $logger;
        $this->runner = $runner;
        $this->docker = $docker;
        $this->powershell = $powershell;
        $this->validator = $validator;
        $this->steps = $steps;

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
    public function __invoke(string $jobID, $image, SsmClient $ssm, $instanceID, array $steps, array $env): bool
    {
        if (!$dockerImage = $this->validator->validate($image)) {
            return $this->bombout(false);
        }

        $this->getIO()->note(self::ACTION_PREPARING_INSTANCE);

        // 1. Parse jobs from steps (a job in this case is a single container which can run multiple steps)
        $jobs = $this->steps->organizeCommandsIntoJobs($dockerImage, $steps);

        $workDir = $this->powershell->getBaseBuildPath();
        $inputDir = "${workDir}\\${jobID}";
        $outputDir = "${workDir}\\${jobID}-output";

        $scriptedJobs = $this->parseCommandsToScripts($jobID, $jobs);

        // 1. Prepare instance to run build commands
        if (!$this->prepare($ssm, $instanceID, $jobID, $inputDir, $scriptedJobs, $env)) {
            return $this->bombout(false);
        }

        $total = count($steps);
        $current = 0;

        foreach ($scriptedJobs as $jobNum => $job) {
            [$image, $scripts] = $job;

            $containerName = ($jobNum + 1) . '_' . strtolower($jobID);

            // 2. Create container
            if (!$this->docker->createContainer($ssm, $instanceID, $image, $containerName)) {
                return $this->bombout(false);
            }

            $this->getIO()->note(self::ACTION_STARTING_CONTAINER);

            // 3. Enable cleanup failsafe
            $cleanup = $this->enableDockerCleanup($ssm, $instanceID, $containerName);

            // 4. Copy into container
            if (!$this->docker->copyIntoContainer($ssm, $instanceID, $jobID, $containerName, $inputDir)) {
                return $this->bombout(false);
            }

            // 5. Start container
            if (!$this->docker->startContainer($ssm, $instanceID, $containerName)) {
                return $this->bombout(false);
            }

            $this->getIO()->note(sprintf(self::ACTION_START_CONTAINER_STARTED, $containerName));

            // 6. Run steps
            foreach ($scripts as $script) {
                $current++;

                if (!$result = $this->runStep($ssm, $instanceID, $containerName, $script, $current, $total)) {
                    return $this->bombout(false);
                }
            }

            // 7. Copy out of container
            if (!$this->docker->copyFromContainer($ssm, $instanceID, $containerName, $outputDir)) {
                return $this->bombout(false);
            }

            // 8. Run and clear docker cleanup/shutdown functionality
            $this->runDockerCleanup($cleanup);

            // 9. Reset the workspace
            // This is necessary because we "shift" between the build dir and output dir.
            // If there are multiple containers used, we need to shift the output to the next job's input.
            if (count($scriptedJobs) > ($jobNum + 1)) {
                if (!$this->shiftBuildWorkspace($ssm, $instanceID, $outputDir, $inputDir)) {
                    return $this->bombout(false);
                }
            }
        }

        return $this->bombout(true);
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $jobID
     * @param string $inputDir
     * @param array $scriptedJobs
     * @param array $env
     *
     * @return bool
     */
    private function prepare(SsmClient $ssm, $instanceID, $jobID, $inputDir, array $scriptedJobs, array $env)
    {
        // We use a custom log context, so we dont mistakenly output secrets in the logs
        $logContext = [
            'instanceID' => $instanceID,
            'commandType' => SSMCommandRunner::TYPE_POWERSHELL
        ];

        // Recombine the [$image, [$steps]] steps into one list so they can be easily written at once
        $combinedScripts = [];
        foreach ($scriptedJobs as $scripts) {
            $combinedScripts = array_merge($combinedScripts, array_pop($scripts));
        }

        $config = [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('verifyBuildEnvironment', [
                    'inputDir' => $inputDir
                ]),
                $this->powershell->getScript('loginDocker'),
                $this->powershell->getScript('writeUserCommandsToBuildScripts', [
                    'buildID' => $jobID,
                    'commandsParsed' => $combinedScripts,
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
     *
     * @param string $containerName
     * @param array $script
     * @param int $currentStep
     * @param int $totalSteps
     *
     * @return bool
     */
    private function runStep(SsmClient $ssm, $instanceID, $containerName, array $script, $currentStep, $totalSteps)
    {
        $remaining = $totalSteps - $currentStep;

        $msg = $this->getEventMessage($script['command'], "[${currentStep}/${totalSteps}]");

        if (!$result = $this->docker->runCommand($ssm, $instanceID, $containerName, $script, $msg)) {
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
     *
     * @param string $outputDir
     * @param string $inputDir
     *
     * @return bool
     */
    private function shiftBuildWorkspace(SsmClient $ssm, $instanceID, $outputDir, $inputDir)
    {
        $config = [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('shiftBuildWorkspaceFromOutput', [
                    'outputDir' => $outputDir,
                    'inputDir' => $inputDir
                ])
            ],
            'executionTimeout' => [$this->internalTimeout],
        ];

        return ($this->runner)(
            $ssm,
            $instanceID,
            SSMCommandRunner::TYPE_POWERSHELL,
            $config,
            [$this->isDebugLoggingEnabled(), self::ERR_SHIFT_FAILED]
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
     * @param array $jobs
     *
     * @return array
     */
    private function parseCommandsToScripts($jobID, array $jobs)
    {
        $scriptedJobs = [];

        foreach ($jobs as $jobNum => $job) {
            [$image, $steps] = $job;

            $scriptedSteps = [];
            foreach ($steps as $stepNum => $step) {
                $num = sprintf('%d_%d', $jobNum + 1, $stepNum + 1);

                $scriptedSteps[] = [
                    'command' => $step,
                    'script' => $this->powershell->getScript('getBuildScript', [
                        'command' => $step,
                        'envFile' => $this->powershell->getUserScriptFilePathForContainer(
                            WindowsSSMDockerinator::CONTAINER_SCRIPTS_DIR,
                            $jobID,
                            'env'
                        )
                    ]),
                    'file' => $this->powershell->getUserScriptFilePath($jobID, $num),
                    'container_file' => $this->powershell->getUserScriptFilePathForContainer(
                        WindowsSSMDockerinator::CONTAINER_SCRIPTS_DIR,
                        $jobID,
                        $num
                    )
                ];
            }

            $scriptedJobs[] = [$image, $scriptedSteps];
        }

        return $scriptedJobs;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $containerName
     *
     * @return void
     */
    private function cleanupContainer(SsmClient $ssm, $instanceID, $containerName)
    {
        $this->getIO()->note(sprintf(self::ACTION_DOCKER_CLEANUP, $containerName));

        $this->docker->cleanupContainer($ssm, $instanceID, $containerName);
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

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @param string $containerName
     *
     * @return callable
     */
    private function enableDockerCleanup(SsmClient $ssm, $instanceID, $containerName)
    {
        $cleanup = function () use ($ssm, $instanceID, $containerName) {
            $this->cleanupContainer($ssm, $instanceID, $containerName);
        };

        // Set emergency handler in case of super fatal
        $this->enableEmergencyHandler($cleanup);

        return $cleanup;
    }

    /**
     * @param callable $cleanup
     *
     * @return void
     */
    private function runDockerCleanup(callable $cleanup)
    {
        $cleanup();
        $this->cleanup(null);
    }
}
