<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Application;

use GuzzleHttp\ClientInterface as GuzzleInterface;

class HalClient
{
    use APIClientTrait;

    public const CONFIG_ENDPOINT = 'endpoint';
    public const CONFIG_AUTH = 'auth';

    private const ERR_ENDPOINT = 'Invalid value for endpoint: Must begin with "http://" or "https://"';
    private const ERR_NEEDS_SECRETS = 'Hal credentials are missing. Please specify $config["auth"]';
    private const ERR_CLIENT_NOT_SET = 'Http client not set.';

    private const ERT_GUZZLE_ERR = 'Unexpected Hal Error: %s';
    private const ERT_INVALID_RESPONSE = 'Invalid response received from Hal. Could not decode JSON from "%s"';

    /**
     * @param GuzzleInterface $guzzle
     * @param array $config
     */
    public function __construct(GuzzleInterface $guzzle, array $config)
    {
        $this->setHttpClient($guzzle);
        $this->loadConfiguration($config);
    }

    /**
     * @param string $applicationID
     * @param string|null $environment
     * @param string|null $ref
     *
     * @return array|null
     */
    public function createBuild(string $applicationID, ?string $environment, ?string $ref): ?array
    {
        $parameters = [
            'reference' => $ref ?: 'master'
        ];

        if ($environment) {
            $parameters['environment'] = $environment;
        }

        $response = $this->request('post', "/applications/${applicationID}/build", $parameters, [200, 201]);
        return $response;
    }
}
