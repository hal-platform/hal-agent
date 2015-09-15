<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Aws\CodeDeploy\CodeDeployClient;
use Aws\Ec2\Ec2Client;
use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\Sdk;
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

    const DECRYPTER_SERVICE = 'decrypter';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type ContainerInterface
     */
    private $di;

    /**
     * @type Sdk
     */
    private $aws;

    /**
     * @type Decrypter|null
     */
    private $decrypter;

    /**
     * Hardcoded, since Enums were removed in aws sdk 3.0
     *
     * @type string[]
     */
    private static $awsRegions = [
        'ap-northeast-1',
        'ap-southeast-2',
        'ap-southeast-1',
        'cn-north-1',
        'eu-central-1',
        'eu-west-1',
        'us-east-1',
        'us-west-1',
        'us-west-2',
        'sa-east-1',
    ];

    /**
     * @param EventLogger $logger
     * @param ContainerInterface $di
     * @param Sdk $aws
     */
    public function __construct(EventLogger $logger, ContainerInterface $di, Sdk $aws)
    {
        $this->logger = $logger;
        $this->di = $di;
        $this->aws = $aws;
    }

    /**
     * @param string $region
     * @param AWSCredential|null $credential
     *
     * @return CodeDeployClient|null
     */
    public function getCD($region, $credential)
    {
        if (!$credentials = $this->getCredentials($region, $credential)) {
            return null;
        }

        return $this->aws->createCodeDeploy($credentials);
    }

    /**
     * @param string $region
     * @param AWSCredential|null $credential
     *
     * @return ElasticBeanstalkClient|null
     */
    public function getEB($region, $credential)
    {
        if (!$credentials = $this->getCredentials($region, $credential)) {
            return null;
        }

        return $this->aws->createElasticBeanstalk($credentials);
    }

    /**
     * @param string $region
     * @param AWSCredential|null $credential
     *
     * @return Ec2Client|null
     */
    public function getEC2($region, $credential)
    {
        if (!$credentials = $this->getCredentials($region, $credential)) {
            return null;
        }

        return $this->aws->createEc2($credentials);
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

        return $this->aws->createS3($credentials);
    }

    /**
     * @param string $region
     * @param AWSCredential|null $credential
     *
     * @return array|null
     */
    public function getCredentials($region, $credential)
    {
        if (!in_array($region, self::$awsRegions, true)) {
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

        return [
            'region' => $region,
            'credentials' => [
                'key' => $credential->key(),
                'secret' => $secret
            ]
        ];
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
                $this->decrypter = $this->di->get(self::DECRYPTER_SERVICE);
            } catch (Exception $ex) {
                $this->logger->event('failure', self::ERR_MISCONFIGURED_ENCRYPTION);

                throw $ex;
            }
        }

        return $this->decrypter;
    }
}
