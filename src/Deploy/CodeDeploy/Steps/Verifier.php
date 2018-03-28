<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */
namespace Hal\Agent\Deploy\CodeDeploy\Steps;

use Aws\CodeDeploy\CodeDeployClient;
use Hal\Agent\AWS\CodeDeployHealthChecker;
use Hal\Agent\Deploy\CodeDeploy\CodeDeployWaiter;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Waiter\Waiter;
use Hal\Agent\Waiter\TimeoutException;

class Verifier
{
    private const ERR_HEALTH = 'Deployment health has invalid status "%s"';
    private const ERR_TIMEOUT = 'Timeout waiting for deployment to finish';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var HealthChecker
     */
    private $healthChecker;

    /**
     * @var Waiter
     */
    private $waiter;

    /**
     * @var CodeDeployWaiter
     */
    private $deployWaiter;

    /**
     * @param EventLogger $logger
     * @param CodeDeployHealthChecker $healthChecker
     *
     * @param Waiter $waiter
     * @param CodeDeployWaiter $deployWaiter
     */
    public function __construct(
        EventLogger $logger,
        CodeDeployHealthChecker $healthChecker,
        Waiter $waiter,
        CodeDeployWaiter $deployWaiter
    ) {
        $this->logger = $logger;
        $this->healthChecker = $healthChecker;

        $this->waiter = $waiter;
        $this->deployWaiter = $deployWaiter;
    }

    /**
     * @param CodeDeployClient $client
     * @param string $application
     * @param string $group
     *
     * @return bool
     */
    public function isDeploymentGroupHealthy(CodeDeployClient $client, string $application, string $group): bool
    {
        $result = $this->healthChecker->getLastDeploymentHealth($client, $application, $group);

        return $this->isStatusValid($result['status'], ['Succeeded', 'Failed', 'Stopped', 'None', 'Ready']);
    }

    /**
     * @param CodeDeployClient $client
     * @param string $deploymentID
     *
     * @return bool
     */
    public function checkDeploymentHealth(CodeDeployClient $client, string $deploymentID): bool
    {
        $result = $this->healthChecker->getDeploymentHealth($client, $deploymentID);

        return $this->isStatusValid($result['status'], ['Succeeded', 'Ready']);
    }

    /**
     * @param CodeDeployClient $client
     * @param string $deploymentID
     *
     * @return bool
     */
    public function waitForHealth(CodeDeployClient $client, string $deploymentID): bool
    {
        $waiter = ($this->deployWaiter)($client, $deploymentID);

        try {
            $this->waiter->wait($waiter);

        } catch (TimeoutException $e) {
            $this->logger->event('failure', static::ERR_TIMEOUT);
            return false;
        }

        return true;
    }

    /**
     * @param string $status
     * @param array $validStatuses
     *
     * @return bool
     */
    private function isStatusValid(string $status, array $validStatuses)
    {
        if (!in_array($status, $validStatuses)) {
            $this->logger->event('failure', sprintf(static::ERR_HEALTH, $status), [
                'Status' => $status,
                'Valid Statuses' => $validStatuses
            ]);

            return false;
        }

        return true;
    }
}
