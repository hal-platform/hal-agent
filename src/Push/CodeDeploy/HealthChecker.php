<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\CodeDeploy;

use Aws\CodeDeploy\CodeDeployClient;
use Aws\CodeDeploy\Exception\CodeDeployException;
use Aws\ResultInterface;

/**
 * Get the health status for a codedeploy group
 *
 * Status:
 *     - Created
 *     - Queued
 *     - InProgress
 *     - Succeeded
 *     - Failed
 *     - Stopped
 *
 *     - Invalid
 *     - None
 *
 */
class HealthChecker
{
    const STATUS_INVALID = 'Invalid';
    const STATUS_NEVER = 'None';

    /**
     * @param CodeDeployClient $cd
     * @param string $cdName
     * @param string $cdGroup
     *
     * @return array
     */
    public function __invoke(CodeDeployClient $cd, $cdName, $cdGroup)
    {
        try {
            $result = $cd->listDeployments([
                'applicationName' => $cdName,
                'deploymentGroupName' => $cdGroup
            ]);

            $lastDeploymentID = $result->search('deployments[0]');
            if (!$lastDeploymentID) {
                return $this->buildResponse(self::STATUS_NEVER);
            }

        } catch (CodeDeployException $ex) {
            return $this->buildResponse(self::STATUS_INVALID);
        }

        return $this->getDeploymentHealth($cd, $lastDeploymentID);
    }

    /**
     * @param CodeDeployClient $cd
     * @param string $id
     *
     * @return array
     */
    public function getDeploymentHealth(CodeDeployClient $cd, $id)
    {
        $result = $cd->getDeployment(['deploymentId' => $id]);
        return $this->parseStatus($result);
    }

    /**
     * @param ResultInterface $result
     *
     * @return array
     */
    private function parseStatus(ResultInterface $result)
    {
        $status = $result->search('deploymentInfo.status');
        $overview = $result->search('deploymentInfo.deploymentOverview');
        $error = $result->search('deploymentInfo.errorInformation');

        return $this->buildResponse($status, $overview, $error);
    }

    /**
     * @param string $status
     * @param array|null $overview
     * @param array|null $error
     *
     * @return array
     */
    private function buildResponse($status, $overview = null, $error = null)
    {
        return [
            'status' => $status,
            'overview' => $overview,
            'error' => $error
        ];
    }
}
