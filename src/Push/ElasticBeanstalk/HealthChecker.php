<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
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

    /**
     * @param ElasticBeanstalkClient $eb
     * @param string $applicationName
     * @param string $environmentId
     *
     * @return array
     */
    public function __invoke(ElasticBeanstalkClient $eb, $applicationName, $environmentId)
    {
        $result = $eb->describeEnvironments([
            'ApplicationName' => $applicationName,
            'EnvironmentIds' => [$environmentId]
        ]);

        if (!$environment = $result->search('Environments[0]')) {
            return $this->getStatus(self::NON_STANDARD_MISSING);
        }

        return $this->getStatus(
            $result->search('Environments[0].Status'),
            $result->search('Environments[0].Health')
        );
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
