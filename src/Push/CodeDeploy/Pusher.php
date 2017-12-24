<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\CodeDeploy;

use Aws\Exception\AwsException;
use Aws\CodeDeploy\CodeDeployClient;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Waiter\TimeoutException;
use Hal\Agent\Waiter\Waiter;

class Pusher
{
    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Code Deployment';
    const ERR_ALREADY_EXISTS = 'Application version already exists';
    const ERR_WAITING = 'Waited for deployment to finish, but the operation timed out.';

    const INFO_STILL_DEPLOYING = 'Still deploying. Completed %d of %d';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var HealthChecker
     */
    private $health;

    /**
     * @var Waiter
     */
    private $waiter;

    /**
     * @var string
     */
    private $halBaseURL;

    /**
     * @param EventLogger $logger
     * @param HealthChecker $health
     * @param Waiter $waiter
     * @param string $halBaseURL
     */
    public function __construct(EventLogger $logger, HealthChecker $health, Waiter $waiter, $halBaseURL)
    {
        $this->logger = $logger;
        $this->health = $health;
        $this->waiter = $waiter;
        $this->halBaseURL = $halBaseURL;
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
        //
        $cdApplication,
        $cdGroup,
        $cdConfiguration,
        //
        $s3bucket,
        $s3version,
        //
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

                'description' => sprintf('[%s]%s/pushes/%s', $environmentName, $this->halBaseURL, $pushId),

                'ignoreApplicationStopFailures' => false,
                'fileExistsBehavior' => 'OVERWRITE',

                'revision' => [
                    'revisionType' => 'S3',
                    's3Location' => [
                        'bucket' => $s3bucket,
                        'bundleType' => $this->detectBundleType($s3version), // 'tar|tgz|zip'
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
            $health = $this->health->getDeploymentInstancesHealth($cd, $deployID);
            $context = array_merge($context, $health);

            // unknown if deployment succeeded. Log the timeout and report as a failure.
            $this->logger->event('failure', self::ERR_WAITING, $context);
            return false;
        }

        // We waited, now get the results
        $health = $this->health->getDeploymentInstancesHealth($cd, $deployID);
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
     * RUNS AT LEAST 3 ITERATIONS (1 minute), unless aws error occurs.
     *
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
        return function () use ($cd, $deployID, &$iteration) {
            try {
                $health = $this->health->getDeploymentInstancesHealth($cd, $deployID);

            } catch (AwsException $e) {
                // Some unknown error
                return true;
            }

            // deployment is still running if following states
            if ($iteration > 3 && !in_array($health['status'], ['Created', 'Queued', 'InProgress'])) {
                return true;
            }

            // Pop a status every 9 iterations (3 minutes, using 20s interval)
            if (++$iteration % 9 === 0) {
                $this->logOngoingDeploymentHealth($health);
            }
        };
    }

    /**
     * Detect the bundle type based on the s3 file extension.
     *
     * http://docs.aws.amazon.com/codedeploy/latest/APIReference/API_S3Location.html#CodeDeploy-Type-S3Location-bundleType
     *
     * Supported types:
     * - 'tar'
     * - 'tgz'
     * - 'zip'
     *
     * @param string $s3file
     *
     * @return string
     */
    private function detectBundleType($s3file)
    {
        $supported = [
            '.zip' => 'zip',
            '.tgz' => 'tgz',
            '.tar.gz' => 'tgz',
        ];

        foreach ($supported as $extension => $bundleType) {
            if (1 === preg_match('/' .  preg_quote($extension) . '$/', $s3file)) {
                return $bundleType;
            }
        }

        // fall back to "tgz"
        return 'tgz';
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
            'overview' => $health['overview'],
            'instancesSummary' => $health['instancesSummary'] ?? ''
        ]);
    }
}
