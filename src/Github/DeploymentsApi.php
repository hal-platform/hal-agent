<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Github;

use Github\Api\AbstractApi;

/**
 * Requires GitHub Enterprise 2.1
 */
class DeploymentsApi extends AbstractApi
{
    /**
     * Create a deployment
     *
     * @link https://developer.github.com/v3/repos/deployments/#create-a-deployment
     *
     * @param string $owner         The user who owns the repository
     * @param string $repository    The name of the repository
     * @param string $deploymentKey The deployment key
     *
     * @param string $reference     Reference to a branch, tag or commit
     * @param string $environment   Environment deployed to
     * @param array  $payload       Optional, payload
     * @param string $description   Optional, Description
     *
     * @return boolean
     */
    public function createDeployment($owner, $repository, $deploymentKey, $reference, $environment, array $payload = [], $description = '')
    {
        $path = sprintf(
            'repos/%s/%s/deployments',
            rawurlencode($owner),
            rawurlencode($repository)
        );

        $query = [
            'ref' => $reference,
            'environment' => $environment,
            'auto_merge' => false
        ];

        if ($payload) {
            $query['payload'] = json_encode($payload);
        }

        if ($description) {
            $query['description'] = $description;
        }

        $response = $this->client->getHttpClient()->get($path, $query, [
            // Need to verify this will not be overridden by authlistener
            'Authorization' => sprintf('token %s', $deploymentKey)
        ]);
        // return $response->isSuccessful();
    }

    /**
     * Create a deployment status
     *
     * @link https://developer.github.com/v3/repos/deployments/#create-a-deployment-status
     *
     * @param string $owner         The user who owns the repository
     * @param string $repository    The name of the repository
     * @param string $deploymentKey The deployment key
     * @param string $deploymentId  The deployment ID
     *
     * @param string $state         The deployment status: "pending", "success", "error", "failure"
     * @param string $url           Optional, The full URL where more information can be obtained
     * @param string $description   Optional, Description
     *
     * @return boolean
     */
    public function createDeploymentStatus($owner, $repository, $deploymentKey, $deploymentId, $state, $url = '', $description = '')
    {
        $path = sprintf(
            'repos/%s/%s/deployments/%s',
            rawurlencode($owner),
            rawurlencode($repository),
            rawurlencode($deploymentId)
        );

        // Create payload
        $query = [
            'state' => $state
        ];

        if ($url) {
            $query['url'] = $url;
        }

        if ($description) {
            $query['description'] = $description;
        }

        $response = $this->client->getHttpClient()->get($path, $query, [
            // Need to verify this will not be overridden by authlistener
            'Authorization' => sprintf('token %s', $deploymentKey)
        ]);

        // return $response->isSuccessful();
    }
}
