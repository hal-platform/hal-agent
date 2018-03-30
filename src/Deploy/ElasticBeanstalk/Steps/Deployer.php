<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticBeanstalk\Steps;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Waiter\TimeoutException;
use Hal\Agent\Waiter\Waiter;

class Deployer
{
    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Code Deployment';
    const ERR_ALREADY_EXISTS = 'Application version already exists';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @param EventLogger $logger
     */
    public function __construct(EventLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param ElasticBeanstalkClient $eb
     * @param string $application
     * @param string $environment
     * @param string $s3version
     * @param string $s3bucket
     * @param string $releaseID
     * @param string $environmentName
     * @param string $description
     *
     * @return bool
     */
    public function __invoke(
        ElasticBeanstalkClient $eb,
        string $application,
        string $environment,
        string $s3bucket,
        string $s3version,
        string $releaseID,
        string $environmentName,
        string $description
    ) : bool {
        $context = [
            'elasticBeanstalkApplication' => $application,
            'elasticBeanstalkEnvironment' => $environment,
            'version' => $releaseID,

            'bucket' => $s3bucket,
            'object' => $s3version
        ];

        try {
            // Error out if version already exists
            if ($this->doesVersionAlreadyExist($eb, $application, $releaseID)) {
                $this->logger->event('failure', self::ERR_ALREADY_EXISTS, $context);
                return false;
            }

            // create version
            $result = $eb->createApplicationVersion([
                'ApplicationName' => $application,
                'VersionLabel' => $releaseID,
                'Description' => $description,
                'SourceBundle' => [
                    'S3Bucket' => $s3bucket,
                    'S3Key' => $s3version
                ]
            ]);

            $prop = 'EnvironmentName';
            if (substr($environment, 0, 2) === 'e-') {
                $prop = 'EnvironmentId';
            }

            // update environment
            $result = $eb->updateEnvironment([
                $prop => $environment,
                'VersionLabel' => $releaseID
            ]);

        } catch (AwsException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);

            return false;

        } catch (CredentialsException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);

            return false;
        }

        return true;
    }

    /**
     * @param ElasticBeanstalkClient $eb
     * @param string $application
     * @param string $releaseID
     *
     * @return bool
     */
    private function doesVersionAlreadyExist(ElasticBeanstalkClient $eb, $application, $releaseID)
    {
        $result = $eb->describeApplicationVersions([
            'ApplicationName' => $application,
            'VersionLabels' => [$releaseID]
        ]);

        if ($result->get('ApplicationVersions')) {
            return true;
        }

        return false;
    }
}
