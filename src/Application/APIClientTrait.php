<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Application;

use Exception;
use GuzzleHttp\ClientInterface as GuzzleInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

trait APIClientTrait
{
    use APIErrorTrait;

    /**
     * @var GuzzleInterface
     */
    private $guzzle;

    /**
     * @var array
     */
    private $config = [];

    /**
     * @param GuzzleInterface $guzzle
     *
     * @return void
     */
    public function setHttpClient(GuzzleInterface $guzzle): void
    {
        $this->guzzle = $guzzle;
    }

    /**
     * Load all optional and required configuration values
     *
     * @param array $config
     * @param array $defaults
     *
     * @return void
     */
    private function loadConfiguration(array $config, array $defaults = []): void
    {
        $this->config = $defaults + $config;
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    private function config($key)
    {
        if (isset($this->config[$key])) {
            return $this->config[$key];
        }

        return null;
    }

    /**
     * @param string $method
     * @param string $path
     * @param array $parameters
     * @param array $allowedResponseCodes
     *
     * @return array|null
     */
    private function request(string $method, string $path, array $parameters, array $allowedResponseCodes = []): ?array
    {
        if (!$this->guzzle) {
            throw new Exception(static::ERR_CLIENT_NOT_SET);
        }

        $url = $this->buildURL($path);
        $options = $this->buildOptions($parameters);

        try {
            $response = $this->guzzle->request($method, $url, $options);

        } catch (RuntimeException $ex) {
            $msg = sprintf(static::ERT_GUZZLE_ERR, "FAILED: ${method} ${url}");
            throw new Exception($msg, $ex->getCode(), $ex);
        }

        $json = $this->validateJSON($url, (string) $response->getBody());

        // todo - check if response is problem+api
        if ($response->getHeaderLine('Content-Type') === 'application/problem+json') {
            $errors = $json['errors'] ?? [];
            foreach ($errors as $field => $fieldErrors) {
                foreach ($fieldErrors as $error) {
                    $this->addError($error, $field);
                }
            }
        }

        if ($allowedResponseCodes) {
            $code = $response->getStatusCode();
            if (!$this->validateResponseCode($code, $allowedResponseCodes)) {
                $this->addError("FAILED: Unexpected response code from ${method} ${url}: ${code}");
                return null;
            }
        }

        return $json;
    }

    /**
     * @param string $path
     *
     * @return string
     */
    private function buildURL($path): string
    {
        $endpoint = $this->config(static::CONFIG_ENDPOINT);

        if (1 !== preg_match('/^https?\:\/\//', $endpoint)) {
            throw new Exception(static::ERR_ENDPOINT);
        }

        return rtrim($endpoint, '/') . $path;
    }

    /**
     * @param array $parameters
     *
     * @return array
     */
    private function buildOptions(array $parameters): array
    {
        $options = [
            'json' => $parameters,
            'headers' => []
        ];

        if (!$auth = $this->config(static::CONFIG_AUTH)) {
            throw new Exception(static::ERR_NEEDS_SECRETS);
        }

        $options['headers']['Authorization'] = sprintf('token %s', $auth);

        return $options;
    }

    /**
     * @param int $responseCode
     * @param array $allowedCodes
     *
     * @return bool
     */
    private function validateResponseCode(int $responseCode, array $allowedCodes): bool
    {
        if (in_array($responseCode, $allowedCodes)) {
            return true;
        }

        return false;
    }

    /**
     * @param string $url
     * @param string $body
     *
     * @return array
     */
    private function validateJSON($url, $body)
    {
        $json = json_decode($body, true);
        $json = is_array($json) ? $json : [];

        if (\JSON_ERROR_NONE !== json_last_error()) {
            throw new Exception(sprintf(static::ERT_INVALID_RESPONSE, $url));
        }

        return $json;
    }
}
