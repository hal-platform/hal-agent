<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticBeanstalk\Steps;

use Aws\Exception\AwsException;
use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Waiter\TimeoutException;
use Hal\Agent\Waiter\Waiter;

class DeploymentVerifier
{
    /**
     * @var string
     */
    const ERR_WAITING = 'Waited for deployment to finish, but the operation timed out.';
    const INFO_STILL_DEPLOYING = 'Still deploying. Latest status: %s';
    const INFO_WAITING_HEALTH_CHECK = 'Deployment finished. Waiting for health check';

    const EVENT_MESSAGE = 'Code Deployment';

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
     * Wait additionalWaitTimeSeconds in seconds.
     *
     * @var float
     */
    private $additionalWaitTimeSeconds;

    /**
     * @param EventLogger $logger
     * @param HealthChecker $health
     * @param Waiter $waiter
     * @param int $additionalWaitTimeSeconds
     */
    public function __construct(EventLogger $logger, HealthChecker $health, Waiter $waiter, int $additionalWaitTimeSeconds)
    {
        $this->logger = $logger;
        $this->health = $health;
        $this->waiter = $waiter;
        $this->additionalWaitTimeSeconds = $additionalWaitTimeSeconds;
    }

    /**
     * @param ElasticBeanstalkClient $eb
     * @param string $application
     * @param string $environment
     *
     * @return bool
     */
    public function __invoke(ElasticBeanstalkClient $eb, string $application, string $environment) : bool
    {
        // Wait for deployment to finish
        if (!$this->waitWhileUpdating($eb, $application, $environment)) {
            // unknown if deployment succeeded. Log the timeout and report as a failure.
            $this->logger->event('failure', self::ERR_WAITING);
            return false;
        }

        // Deployment has finished
        $this->logger->event('success', self::INFO_WAITING_HEALTH_CHECK);

        // This ensures we wait a minimum time after a successful beanstalk deploy,
        // to allow time for healthchecks to update.
        usleep($this->additionalWaitTimeSeconds);

        // We waited, now get the results
        $health = call_user_func($this->health, $eb, $application, $environment);

        // success
        if ($health['health'] === 'Green') {
            $this->logger->event('success', self::EVENT_MESSAGE, $health);
            return true;
        }

        $this->logger->event('failure', self::EVENT_MESSAGE, $health);

        return false;
    }

    /**
     * Custom waiter logic because amazon removed a lot of waiters between v2 and v3 of the sdk. LAME.
     *
     * @param ElasticBeanstalkClient $eb
     * @param string $applicationName
     * @param string $environment
     *
     * @return bool
     */
    private function waitWhileUpdating(ElasticBeanstalkClient $eb, $applicationName, $environment)
    {
        $waiter = $this->buildWaiter($eb, $applicationName, $environment);
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
     * @param string $applicationName
     * @param string $environment
     *
     * @return callable
     */
    private function buildWaiter(ElasticBeanstalkClient $eb, $applicationName, $environment)
    {
        $iteration = 0;
        return function () use ($eb, $applicationName, $environment, &$iteration) {
            try {
                $health = call_user_func($this->health, $eb, $applicationName, $environment);
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
}
