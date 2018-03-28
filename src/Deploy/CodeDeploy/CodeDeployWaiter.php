<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\CodeDeploy;

use Aws\CodeDeploy\CodeDeployClient;
use Hal\Agent\Deploy\CodeDeploy\HealthChecker;
use Hal\Agent\Logger\EventLogger;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;

class CodeDeployWaiter
{
    private const INFO_STILL_DEPLOYING = 'Still deploying. Completed %d of %d';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var HealthChecker
     */
    private $health;

    /**
     * @param EventLogger $logger
     * @param HealthChecker $health
     */
    public function __construct(EventLogger $logger, HealthChecker $health)
    {
        $this->logger = $logger;
        $this->health = $health;
    }

    /**
     * @param CodeDeployClient $cd
     * @param string $deployID
     *
     * @return callable
     */
    public function __invoke(CodeDeployClient $cd, string $deployID)
    {
        $iteration = 0;

        return function () use ($cd, $deployID, &$iteration) {
            try {
                $health = $this->health->getDeploymentInstancesHealth($cd, $deployID);
            } catch (AwsException $e) {
                return true;
            } catch (CredentialsException $e) {
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
     * @param array $health
     */
    private function logOngoingDeploymentHealth(array $health)
    {
        $success = isset($health['overview']['Succeeded']) ? $health['overview']['Succeeded'] : 0;
        $total = array_sum($health['overview']);

        $msg = sprintf(self::INFO_STILL_DEPLOYING, $success, $total);

        $this->logger->event('info', $msg, [
            'Status' => $health['status'],
            'Overview' => $health['overview'],
            'Instances Summary' => $health['instancesSummary'] ?? ''
        ]);
    }
}
