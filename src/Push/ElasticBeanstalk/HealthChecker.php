<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use Aws\ElasticBeanstalk\ElasticBeanstalkClient;

/**
 * Get the health status for an elastic beanstalk environment
 *
 * Status:
 *     - Launching
 *     - Updating
 *     - Ready
 *     - Terminating
 *     - Terminated
 *
 *     - Missing
 *     - Too Many Cooks
 *
 * Health:
 *     - Red
 *     - Yellow
 *     - Green
 *     - Grey
 *
 */
class HealthChecker
{
    const NON_STANDARD_MISSING = 'Missing';
    const NON_STANDARD_MULTIPLE = 'Too Many Cooks';

    /**
     * @param ElasticBeanstalkClient $eb
     * @param string $applicationName
     * @param string $environmentId
     *
     * @return array
     */
    public function __invoke(ElasticBeanstalkClient $eb, $applicationName, $environmentId)
    {
        $environments = $eb->describeEnvironments([
            'ApplicationName' => $applicationName,
            'EnvironmentIds' => [$environmentId]
        ]);

        // sanity check
        if (!isset($environments['Environments'])) {
            return $this->getStatus(self::NON_STANDARD_MISSING);
        }

        $environments = $environments['Environments'];
        if (count($environments) !== 1) {
            $status = (count($environments) === 0) ? self::NON_STANDARD_MISSING : self::NON_STANDARD_MULTIPLE;
            return $this->getStatus($status);
        }

        $environment = $environments[0];

        return $this->getStatus($environment['Status'], $environment['Health']);
    }

    /**
     * @param string $status
     * @param string $health
     *
     * @return array
     */
    private function getStatus($status = 'Unknown', $health = 'Grey')
    {
        return [
            'status' => $status,
            'health' => $health
        ];
    }
}
