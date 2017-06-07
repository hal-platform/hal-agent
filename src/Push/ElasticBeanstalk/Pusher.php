<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\ElasticBeanstalk;

use Aws\Exception\AwsException;
use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
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

    const INFO_STILL_DEPLOYING = 'Still deploying. Latest status: %s';
    const INFO_WAITING_HEALTH_CHECK = 'Deployment finished. Waiting for health check';

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
     * @param ElasticBeanstalkClient $eb
     * @param string $awsApplication
     * @param string $awsEnvironment
     * @param string $s3version
     * @param string $buildId
     * @param string $pushId
     * @param string $environmentName
     *
     * @return boolean
     */
    public function __invoke(
        ElasticBeanstalkClient $eb,
        $awsApplication,
        $awsEnvironment,
        $s3bucket,
        $s3version,
        $buildId,
        $pushId,
        $environmentName
    ) {
        $context = [
            'elasticBeanstalkApplication' => $awsApplication,
            'elasticBeanstalkEnvironment' => $awsEnvironment,
            'version' => $pushId,

            'bucket' => $s3bucket,
            'object' => $s3version
        ];

        try {

            // Error out if version already exists
            if ($this->doesVersionAlreadyExist($eb, $awsApplication, $pushId)) {
                $this->logger->event('failure', self::ERR_ALREADY_EXISTS, $context);
                return false;
            }

            // create version
            $result = $eb->createApplicationVersion([
                'ApplicationName' => $awsApplication,
                'VersionLabel' => $pushId,
                'Description' => sprintf('Build %s, Env %s', $buildId, $environmentName),
                'SourceBundle' => [
                    'S3Bucket' => $s3bucket,
                    'S3Key' => $s3version
                ]
            ]);

            $prop = 'EnvironmentName';
            if (substr($awsEnvironment, 0, 2) === 'e-') {
                $prop = 'EnvironmentId';
            }

            // update environment
            $result = $eb->updateEnvironment([
                $prop => $awsEnvironment,
                'VersionLabel' => $pushId
            ]);

        } catch (AwsException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);

            return false;
        }

        // Wait for deployment to finish
        if (!$this->wait($eb, $awsApplication, $awsEnvironment)) {
            // unknown if deployment succeeded. Log the timeout and report as a failure.
            $this->logger->event('failure', self::ERR_WAITING, $context);
            return false;
        }

        // Deployment has finished
        $this->logger->event('success', self::INFO_WAITING_HEALTH_CHECK);
        $this->pauseWait();

        // We waited, now get the results
        $health = call_user_func($this->health, $eb, $awsApplication, $awsEnvironment);
        $context = array_merge($context, $health);

        // success
        if ($health['health'] === 'Green') {
            $this->logger->event('success', self::EVENT_MESSAGE, $context);
            return true;
        }

        $this->logger->event('failure', self::EVENT_MESSAGE, $context);
        return false;
    }

    /**
     * @param ElasticBeanstalkClient $eb
     * @param string $awsApplication
     * @param string $pushId
     *
     * @return bool
     */
    private function doesVersionAlreadyExist(ElasticBeanstalkClient $eb, $awsApplication, $pushId)
    {
        $result = $eb->describeApplicationVersions([
            'ApplicationName' => $awsApplication,
            'VersionLabels' => [$pushId]
        ]);

        if ($result->get('ApplicationVersions')) {
            return true;
        }

        return false;
    }

    /**
     * Custom waiter logic because amazon removed a lot of waiters between v2 and v3 of the sdk. LAME.
     *
     * @param ElasticBeanstalkClient $eb
     * @param string $awsApplication
     * @param string $awsEnvironment
     *
     * @return bool
     */
    private function wait(ElasticBeanstalkClient $eb, $awsApplication, $awsEnvironment)
    {
        $waiter = $this->buildWaiter($eb, $awsApplication, $awsEnvironment);
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
     * @param ElasticBeanstalkClient $eb
     * @param string $awsApplication
     * @param string $awsEnvironment
     *
     * @return callable
     */
    private function buildWaiter(ElasticBeanstalkClient $eb, $awsApplication, $awsEnvironment)
    {
        $iteration = 0;
        return function() use ($eb, $awsApplication, $awsEnvironment, &$iteration) {
            try {
                $health = call_user_func($this->health, $eb, $awsApplication, $awsEnvironment);

            } catch (AwsException $e) {
                // Some unknown error
                return true;
            }

            // deployment is still running if following states
            if ($iteration > 3 && !in_array($health['status'], ['Updating'])) {
                return true;
            }

            // Pop a status every 9 iterations (3 minutes, using 20s interval)
            if (++$iteration % 9 === 0) {
                $this->logger->event('info', sprintf(self::INFO_STILL_DEPLOYING, $health['status']), $health);
            }
        };
    }

    /**
     * Sit still, look pretty.
     *
     * Wait 5 iterations after finished (20s * 5 = 1m40s)
     *
     * This ensures we wait a minimum time after a successful beanstalk deploy,
     * to allow time for healthchecks to update.
     *
     * @return bool
     */
    private function pauseWait()
     {
        $iteration = 0;
        $waiter = function() use (&$iteration) {
            if ($iteration > 4) {
                return true;
            }

            $iteration++;
        };

        try {
            $this->waiter->wait($waiter);
            return true;

        } catch (TimeoutException $e) {
            // timeout expired
            return false;
        }
    }
}
