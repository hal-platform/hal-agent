<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Aws\Common\Enum\Region;
use Aws\Common\Exception\AwsExceptionInterface;
use Aws\S3\S3Client;
use Exception;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Core\Crypto\Decrypter;
use QL\Hal\Core\Entity\Credential\AWSCredential;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AWSAuthenticator
{
    const EVENT_MESSAGE = 'Authenticate with AWS';

    const ERR_INVALID_REGION = 'Invalid AWS region specified.';
    const ERR_INVALID_CREDENTIAL = 'Missing credentials. AWS deployments require authentication credentials.';
    const ERR_INVALID_SECRET = 'Missing credentials. AWS deployments require access secret.';
    const ERR_MISCONFIGURED_ENCRYPTION = 'A serious error occured while decrypting. HAL Agent may not be configured correctly.';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type ContainerInterface
     */
    private $di;

    /**
     * @type Decrypter|null
     */
    private $decrypter;

    /**
     * @param EventLogger $logger
     * @param ContainerInterface $di
     * @param callable $fileStreamer
     */
    public function __construct(EventLogger $logger, ContainerInterface $di)
    {
        $this->logger = $logger;
        $this->di = $di;
    }

    /**
     * @param string $region
     * @param AWSCredential|null $credential
     *
     * @return S3Client|null
     */
    public function getS3($region, $credential)
    {
        if (!$credentials = $this->getCredentials($region, $credential)) {
            return null;
        }

        list($key, $secret) = $credentials;

        $s3 = S3Client::factory([
            'region' => $region,
            'credentials' => [
                'key' => $key,
                'secret' => $secret
            ]
        ]);

        return $s3;
    }

    /**
     * @param string $region
     * @param AWSCredential|null $credential
     *
     * @return array|null
     */
    public function getCredentials($region, $credential)
    {
        if (!in_array($region, Region::values(), true)) {
            $this->logger->event('failure', self::ERR_INVALID_REGION, [
                'specified_region' => $region
            ]);

            return null;
        }

        if (!$credential instanceof AWSCredential) {
            $this->logger->event('failure', self::ERR_INVALID_CREDENTIAL);
            return null;
        }

        if (!$secret = $this->getSecret($credential)) {
            $this->logger->event('failure', self::ERR_INVALID_SECRET);
            return null;
        }

        return [$credential->key(), $secret];
    }

    /**
     * @param AWSCredential $credential
     *
     * @throws Exception - if decrypter is misconfigured
     *
     * @return string
     */
    private function getSecret(AWSCredential $credential)
    {
        if (!$secret = $credential->secret()) {
            return '';
        }

        $decrypter = $this->decrypter();

        try {
            $secret = $decrypter->decrypt($secret);

        } catch (Exception $ex) {
            return '';
        }

        return $secret;
    }

    /**
     * Lazy load the decrypter from the symfony container so we can handle errors a bit better.
     *
     * @return Decrypter|null
     */
    private function decrypter()
    {
        if (!$this->decrypter) {
            try {
                $this->decrypter = $this->di->get('decrypter');
            } catch (Exception $ex) {
                $this->logger->event('failure', self::ERR_MISCONFIGURED_ENCRYPTION);

                throw $ex;
            }
        }

        return $this->decrypter;
    }
}
