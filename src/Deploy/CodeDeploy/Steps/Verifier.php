<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */
namespace Hal\Agent\Deploy\CodeDeploy\Steps;

use Hal\Agent\Deploy\CodeDeploy\CodeDeployWaiter;
use Hal\Agent\Deploy\CodeDeploy\HealthChecker;
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
     * @param HealthChecker $healthChecker
     *
     * @param Waiter $waiter
     * @param CodeDeployWaiter $deployWaiter
     */
    public function __construct(
        EventLogger $logger,
        HealthChecker $healthChecker,
        Waiter $waiter,
        CodeDeployWaiter $deployWaiter
    ) {
        $this->logger = $logger;
        $this->healthChecker = $healthChecker;

        $this->waiter = $waiter;
        $this->deployWaiter = $deployWaiter;
    }

    /**
     * @param array $platformConfig
     *
     * @return bool
     */
    public function checkLastDeploymentHealth(array $platformConfig): bool
    {
        $result = ($this->healthChecker)->getLastDeploymentHealth(
            $platformConfig['sdk']['cd'],
            $platformConfig['application'],
            $platformConfig['group']
        );

        return $this->isStatusValid($result['status'], ['Succeeded', 'Failed', 'Stopped', 'None', 'Ready']);
    }

    /**
     * @param array $platformConfig
     * @param array $deploymentInformation
     *
     * @return bool
     */
    public function checkDeploymentHealth(array $platformConfig, array $deploymentInformation): bool
    {
        $result = ($this->healthChecker)->getDeploymentHealth(
            $platformConfig['sdk']['cd'],
            $deploymentInformation['codeDeployID']
        );

        return $this->isStatusValid($result['status'], ['Succeeded', 'Ready']);
    }

    /**
     * @param array $platformConfig
     * @param array $deploymentInformation
     *
     * @return bool
     */
    public function waitForHealth(array $platformConfig, array $deploymentInformation): bool
    {
        $waiter = ($this->deployWaiter)($platformConfig['sdk']['cd'], $deploymentInformation['codeDeployID']);

        try {
            $this->waiter->wait($waiter);
            return true;
        } catch (TimeoutException $e) {
            $this->logger->event('failure', static::ERR_TIMEOUT);
            return false;
        }
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
            $context = [
                'Status' => $status,
                'Valid Statuses' => $validStatuses
            ];
            $this->logger->event('failure', sprintf(static::ERR_HEALTH, $status), $context);
            return false;
        }

        return true;
    }
}
