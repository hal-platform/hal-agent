<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Logger;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Hal\Core\Entity\Job;

class MetadataHandler
{
    private const ERR_METADATA_FAILED = 'Failed to send Metadata to Hal API';

    private const MAX_METADATA_SIZE_KB = 20;

    /**
     * @var Client
     */
    private $http;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Client $http
     * @param LoggerInterface $logger
     */
    public function __construct(Client $http, LoggerInterface $logger)
    {
        $this->http = $http;
        $this->logger = $logger;
    }

    /**
     * @param Job $job
     * @param string $key
     * @param string $data
     *
     * @return void
     */
    public function send(Job $job, string $key, string $data)
    {
        if (!$key) {
            return;
        }

        // blank out the data if it fails on json encode (ensures utf8 compat)
        // this sucks but it protects us from weird windows encoding
        $encoded = json_encode($data);
        if (JSON_ERROR_NONE !== json_last_error()) {
            $data = '';
        }

        $data = trim($data);
        $size = strlen($data);
        if ($size < 1 || $size > self::MAX_METADATA_SIZE_KB * 1000) {
            return;
        }

        // Sanitize the name of the key
        $name = strtolower($key);
        $name = preg_replace('/[^a-zA-Z0-9\_\.]/', '_', $name);

        $this->sendtoAPI($job, $name, $data);
    }

    /**
     * @param Push $push
     * @param string $name
     * @param string $data
     *
     * @return void
     */
    private function sendtoAPI(Job $job, $name, $data)
    {
        if ($job instanceof Build) {
            $entityType = 'builds';
        } elseif ($job instanceof Release) {
            $entityType = 'releases';
        } else {
            return;
        }

        $url = sprintf(
            'api/%s/%s/metadata/%s',
            $entityType,
            rawurlencode($job->id()),
            rawurlencode($name)
        );

        $response = $this->http->post($url, ['json' => ['data' => $data]]);
        $statusCode = ((int) floor($response->getStatusCode() / 100)) * 100;

         if ($statusCode !== 200) {
            $this->logger->error(self::ERR_METADATA_FAILED, [
                'responseStatus' => $response->getStatusCode(),
                'responseBody' => (string) $response->getBody()
            ]);
        }
    }
}
