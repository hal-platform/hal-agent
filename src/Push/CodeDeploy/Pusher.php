<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\CodeDeploy;

use Aws\Exception\AwsException;
use Aws\CodeDeploy\CodeDeployClient;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Waiter\TimeoutException;
use QL\Hal\Agent\Waiter\Waiter;

class Pusher
{
    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Code Deployment';
    const ERR_ALREADY_EXISTS = 'Application version already exists';
    const ERR_WAITING = 'Waited for deployment to finish, but the operation timed out.';

    const INFO_STILL_DEPLOYING = 'Deployed %d of %d';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type HealthChecker
     */
    private $health;

    /**
     * @type Waiter
     */
    private $waiter;

    /**
     * @param EventLogger $logger
     * @param HealthChecker $health
     * @param Waiter $waiter
     */
    public function __construct(EventLogger $logger, HealthChecker $health, Waiter $waiter)
    {
        $this->logger = $logger;
        $this->health = $health;
        $this->waiter = $waiter;
    }

    /**
     * @param CodeDeployClient $cd
     *
     * @param string $cdApplication
     * @param string $cdGroup
     * @param string $cdConfiguration
     *
     * @param string $s3bucket
     * @param string $s3version
     *
     * @param string $buildId
     * @param string $pushId
     * @param string $environmentName
     *
     * @return boolean
     */
    public function __invoke(
        CodeDeployClient $cd,

        $cdApplication,
        $cdGroup,
        $cdConfiguration,

        $s3bucket,
        $s3version,

        $buildId,
        $pushId,
        $environmentName
    ) {
        $context = [
            'codeDeployApplication' => $cdApplication,
            'codeDeployConfiguration' => $cdConfiguration,
            'codeDeployGroup' => $cdGroup,

            'bucket' => $s3bucket,
            'object' => $s3version
        ];

        try {

            $result = $cd->createDeployment([
                'applicationName' => $cdApplication,
                'deploymentGroupName' => $cdGroup,
                'deploymentConfigName' => $cdConfiguration,

                'description' => sprintf('Build %s, Env %s', $buildId, $environmentName),
                'ignoreApplicationStopFailures' => false,
                'revision' => [
                    'revisionType' => 'S3',
                    's3Location' => [
                        'bucket' => $s3bucket,
                        'bundleType' => 'tgz',
                        'key' => $s3version
                    ]
                ]
            ]);

            $deployID = $result->get('deploymentId');
            $context = array_merge($context, ['codeDeployID' => $deployID]);

        } catch (AwsException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);

            return false;
        }

        // Wait for deployment to finish
        if (!$this->wait($cd, $deployID)) {

            // Get final health to report
            $health = $this->health->getDeploymentHealth($cd, $deployID);
            $context = array_merge($context, $health);

            // unknown if deployment succeeded. Log the timeout and report as a failure.
            $this->logger->event('failure', self::ERR_WAITING, $context);
            return false;
        }

        // We waited, now get the results
        $health = $this->health->getDeploymentHealth($cd, $deployID);
        $context = array_merge($context, $health);

        // success
        if ($health['status'] === 'Succeeded') {
            $this->logger->event('success', self::EVENT_MESSAGE, $context);
            return true;
        }

        $this->logger->event('failure', self::EVENT_MESSAGE, $context);
        return false;
    }

    /**
     * Custom waiter logic because amazon removed a lot of waiters between v2 and v3 of the sdk. LAME.
     *
     * @param CodeDeployClient $cd
     * @param string $deployID
     *
     * @return bool
     */
    private function wait(CodeDeployClient $cd, $deployID)
    {
        $waiter = $this->buildWaiter($cd, $deployID);
        try {
            $this->waiter->wait($waiter);
            return true;

        } catch (TimeoutException $e) {
            // timeout expired
            return false;
        }
    }

    /**
     * Custom waiter logic because amazon removed a lot of waiters between v2 and v3 of the sdk. LAME.
     *
     * @param CodeDeployClient $cd
     * @param string $deployID
     *
     * @return bool
     */
    private function buildWaiter(CodeDeployClient $cd, $deployID)
    {
        $iteration = 0;
        return function() use ($cd, $deployID, &$iteration) {
            try {
                $health = $this->health->getDeploymentHealth($cd, $deployID);

            } catch (AwsException $e) {
                // Some unknown error
                return true;
            }

            // deployment is still running if following states
            if (!in_array($health['status'], ['Created', 'Queued', 'InProgress'])) {
                return true;
            }

            // Pop a status every 12 iterations (4 minutes, using 20s interval)
            if (++$iteration % 12 === 0) {
                $this->logOngoingDeploymentHealth($health);
            }
        };
    }

    /**
     * @param array $health
     *
     * @return void
     */
    private function logOngoingDeploymentHealth($health)
    {
        $success = isset($health['overview']['Succeeded']) ? $health['overview']['Succeeded'] : 0;
        $total = array_sum($health['overview']);

        $msg = sprintf(self::INFO_STILL_DEPLOYING, $success, $total);

        $this->logger->event('info', $msg, [
            'status' => $health['status'],
            'overview' => $health['overview']
        ]);
    }

    /**
     * @param CodeDeployClient $cd
     * @param string $deployID
     *
     * @return array
     */
    private function getDeployInfo(CodeDeployClient $cd, $deployID)
    {
        $result = $cd->getDeployment(['deploymentId' => $deployID]);

        $status = $result->search('deploymentInfo.status');
        $overview = $result->search('deploymentInfo.deploymentOverview');
        $error = $result->search('deploymentInfo.errorInformation');

        return [
            'status' => $status,
            'overview' => $overview,
            'error' => $error
        ];
    }
}
