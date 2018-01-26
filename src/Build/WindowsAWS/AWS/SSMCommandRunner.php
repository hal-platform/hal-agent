<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS\AWS;

use Aws\Exception\AwsException;
use Aws\Ssm\SsmClient;
use InvalidArgumentException;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Waiter\TimeoutException;
use Hal\Agent\Waiter\Waiter;

class SSMCommandRunner
{
    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Run SSM Command';

    const ERR_WAITING = 'Waited for command to finish, but the operation timed out.';

    const TIMEOUT_TO_START_COMMAND = 30;
    const TYPE_POWERSHELL = 'AWS-RunPowerShellScript';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Waiter
     */
    private $waiter;

    /**
     * @var bool
     */
    private $mandatoryWaitPeriod;

    /**
     * @var string
     */
    private $lastOutput;
    private $lastErrorOutput;
    private $lastExitCode;

    /**
     * @param EventLogger $logger
     * @param Waiter $waiter
     */
    public function __construct(EventLogger $logger, Waiter $waiter)
    {
        $this->logger = $logger;
        $this->waiter = $waiter;

        $this->mandatoryWaitPeriod = true;

        $this->resetStatus();
    }

    /**
     * @param bool $waitEnabled
     *
     * @return void
     */
    public function setMandatoryWaitPeriod($waitEnabled = true)
    {
        $this->mandatoryWaitPeriod = (bool) $waitEnabled;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $commandType
     * @param array $options
     * @param array $loggingContext
     *                [$customMessage='', $logContext=[]]
     *
     * @return bool
     */
    public function __invoke(SsmClient $ssm, $instanceID, $commandType, array $options, array $loggingContext = [])
    {
        $this->resetStatus();
        $alwaysLog = (count($loggingContext) > 0) ? array_shift($loggingContext) : false;
        $logMessage = (count($loggingContext) > 0) ? array_shift($loggingContext) : self::EVENT_MESSAGE;

        $logContext = $this->getDefaultLogContext($instanceID, $commandType, $options);
        if (count($loggingContext) > 0) {
            $logContext = array_shift($loggingContext);
        }

        if (!$commandID = $this->executeRun($ssm, $instanceID, $commandType, $options, $logMessage, $logContext)) {
            return false;
        }

        if ($this->mandatoryWaitPeriod) {
            // wait a few seconds. If we call "GetCommandInvocation"
            // too quickly after SendCommand, we get an error.
            sleep(5);
        }

        // Wait for command to finish
        if (!$this->wait($ssm, $instanceID, $commandID)) {
            // unknown if command succeeded. Log the timeout and report as a failure.
            $this->logger->event('failure', self::ERR_WAITING, $logContext);
            return false;
        }

        // We waited, now get the results
        if ($command = $this->getCommandStatus($ssm, $instanceID, $commandID)) {
            $this->lastExitCode = $logContext['exitCode'] = $command['ResponseCode'];
            $this->lastOutput = $logContext['output'] = $command['StandardOutputContent'];
            $this->lastErrorOutput = $logContext['errorOutput'] = $command['StandardErrorContent'];

            if ($command['Status'] === 'Success') {
                if ($alwaysLog) {
                    $this->logger->event('success', $logMessage, $logContext);
                }

                return true;
            }

            $logContext['status'] = $command['Status'];
        }

        $this->logger->event('failure', $logMessage, $logContext);
        return false;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $commandID
     *
     * @return array|null
     */
    public function getCommandStatus(SsmClient $ssm, $instanceID, $commandID)
    {
        try {
            $result = $ssm->getCommandInvocation([
                'InstanceId' => $instanceID,
                'CommandId' => $commandID,
            ]);

        } catch (AwsException $e) {
            return null;
        }

        return $result->toArray();
    }

    /**
     * @return string
     */
    public function getLastOutput()
    {
        return $this->lastOutput;
    }

    /**
     * @return string
     */
    public function getLastStatus()
    {
        return [
            'output' => $this->lastOutput,
            'errorOutput' => $this->lastErrorOutput,
            'exitCode' => $this->lastExitCode,
        ];
    }
    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $commandType
     * @param array $options
     * @param string $logMessage
     * @param array $logContext
     *
     * @return string|null
     */
    private function executeRun(SsmClient $ssm, $instanceID, $commandType, array $options, string $logMessage, array $logContext)
    {
        try {
            $result = $ssm->sendCommand([
                'InstanceIds' => [$instanceID],
                'DocumentName' => $commandType,
                'TimeoutSeconds' => self::TIMEOUT_TO_START_COMMAND,
                'Parameters' => $options,

                // 'OutputS3BucketName' => '<string>',
                // 'OutputS3KeyPrefix' => '<string>',
                // 'ServiceRoleArn' => '<string>',
            ]);

            $commandID = $result->search('Command.CommandId');

        } catch (AwsException $e) {
            $this->logger->event('failure', $logMessage, ['error' => $e->getMessage()] + $logContext);
            return null;

        } catch (InvalidArgumentException $e) {
            $this->logger->event('failure', $logMessage, ['error' => $e->getMessage()] + $logContext);
            return null;
        }

        return $commandID;
    }

    /**
     * @param string $instanceID
     * @param string $commandType
     * @param array $commandOptions
     *
     * @return array
     */
    private function getDefaultLogContext($instanceID, $commandType, array $commandOptions)
    {
        $logOptions = [];
        foreach ($commandOptions as $name => $values) {
            $flattened = is_array($values) ? implode("\n", $values) : $values;
            $logOptions[] = "${name}:\n" . $flattened . "\n";
        }

        return [
            'instanceID' => $instanceID,
            'commandType' => $commandType,
            'commandOptions' => implode("\n", $logOptions)
        ];
    }

    /**
     * @return void
     */
    private function resetStatus()
    {
        $this->lastOutput = '';
        $this->lastErrorOutput = '';
        $this->lastExitCode = '';
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $commandID
     *
     * @return bool
     */
    private function wait(SsmClient $ssm, $instanceID, $commandID)
    {
        $waiter = $this->buildWaiter($ssm, $instanceID, $commandID);
        try {
            $this->waiter->wait($waiter);
            return true;

        } catch (TimeoutException $e) {
            // timeout expired
            return false;
        }
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $commandID
     *
     * @return callable
     */
    private function buildWaiter(SsmClient $ssm, $instanceID, $commandID)
    {
        return function () use ($ssm, $instanceID, $commandID) {
            $command = $this->getCommandStatus($ssm, $instanceID, $commandID);
            if (!$command) {
                // Some unknown error
                return true;
            }

            // command is still running if in following states
            if (!in_array($command['Status'], ['Pending', 'InProgress', 'Delayed'])) {
                return true;
            }
        };
    }
}
