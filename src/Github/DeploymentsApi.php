<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Github;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ParseException;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\UriTemplate;

/**
 * Requires GitHub Enterprise 2.1
 */
class DeploymentsApi
{
    /**
     * @var Guzzle
     */
    private $guzzle;

    /**
     * @var string
     */
    private $ghApiBaseUrl;

    /**
     * @param Guzzle $guzzle
     * @param string $ghApiBaseUrl
     */
    public function __construct(Guzzle $guzzle, $ghApiBaseUrl)
    {
        $this->guzzle = $guzzle;
        $this->ghApiBaseUrl = rtrim($ghApiBaseUrl, '/');
    }

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
     * @return int|null
     */
    public function createDeployment($owner, $repository, $deploymentKey, $reference, $environment, $description = '', array $payload = [])
    {
        $apiUrl = (new UriTemplate)->expand($this->ghApiBaseUrl . '/repos/{owner}/{repo}/deployments', [
            'owner' => $owner,
            'repo' => $repository
        ]);

        $body = [
            'ref' => $reference,
            'environment' => $environment,
            'auto_merge' => false
        ];

        if ($payload) {
            $body['payload'] = json_encode($payload);
        }

        if ($description) {
            $body['description'] = $description;
        }

        $response = $this->guzzle->post($apiUrl, [
            'headers' => [
                'Authorization' => sprintf('token %s', $deploymentKey),
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($body),
            'exceptions' => false
        ]);

        // fail
        if (!$decoded = $this->parseResponse($response)) {
            return null;
        }

        if (!array_key_exists('id', $decoded)) {
            return null;
        }

        return (int) $decoded['id'];
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
        $apiUrl = (new UriTemplate)->expand($this->ghApiBaseUrl . '/repos/{owner}/{repo}/deployments/{id}/statuses', [
            'owner' => $owner,
            'repo' => $repository,
            'id' => $deploymentId
        ]);

        // Create payload
        $body = [
            'state' => $state
        ];

        if ($url) {
            $body['target_url'] = $url;
        }

        if ($description) {
            $body['description'] = $description;
        }

        $response = $this->guzzle->post($apiUrl, [
            'headers' => [
                'Authorization' => sprintf('token %s', $deploymentKey),
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($body),
            'exceptions' => false
        ]);

        // fail
        if (!$decoded = $this->parseResponse($response)) {
            return false;
        }

        return true;
    }

    /**
     * @param ResponseInterface $response
     *
     * @return array|null
     */
    private function parseResponse(ResponseInterface $response)
    {
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return null;
        }

        try {
            $decoded = $response->json();
        } catch (ParseException $e) {
            return null;
        }

        return $decoded;
    }
}
