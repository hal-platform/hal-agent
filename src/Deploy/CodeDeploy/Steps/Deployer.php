<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\CodeDeploy\Steps;

use Aws\CodeDeploy\CodeDeployClient;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Hal\Agent\Logger\EventLogger;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;

class Deployer
{
    /**
     * @var string
     */
    private const EVENT_MESSAGE = 'Deploy new application version with AWS CodeDeploy';
    private const INFO_STILL_DEPLOYING = 'Still deploying. Completed attempt %d';

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
     * @param Job $job
     * @param CodeDeployClient $cd
     *
     * @param string $bucket
     * @param string $remotePath
     *
     * @param string $application
     * @param string $group
     * @param string $configuration
     *
     * @return array|null
     */
    public function __invoke(
        Job $job,
        CodeDeployClient $cd,
        string $bucket,
        string $remotePath,
        string $application,
        string $group,
        string $configuration,
        string $description
    ): ?array {
        if (!$job instanceof Build && !$job instanceof Release) {
            return null;
        }

        $context = [
            'codeDeployApplication' => $application,
            'codeDeployConfiguration' => $configuration,
            'codeDeployGroup' => $group,

            'bucket' => $bucket,
            'object' => $remotePath
        ];

        try {
            $result = $cd->createDeployment([
                'applicationName' => $application,
                'deploymentGroupName' => $group,
                'deploymentConfigName' => $configuration,

                'description' => $description,

                'ignoreApplicationStopFailures' => false,
                'fileExistsBehavior' => 'OVERWRITE',

                'revision' => [
                    'revisionType' => 'S3',
                    's3Location' => [
                        'bucket' => $bucket,
                        'bundleType' => $this->detectBundleType($remotePath), // 'tar|tgz|zip'
                        'key' => $remotePath
                    ]
                ]
            ]);

            $deployID = $result->get('deploymentId');
            $context = array_merge($context, ['codeDeployID' => $deployID]);

        } catch (AwsException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', static::EVENT_MESSAGE, $context);
            return null;

        } catch (CredentialsException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', static::EVENT_MESSAGE, $context);
            return null;
        }

        return $context;
    }

    /**
     * Detect the bundle type based on the s3 file extension.
     *
     * http://docs.aws.amazon.com/codedeploy/latest/APIReference/API_S3Location.html#CodeDeploy-Type-S3Location-bundleType
     *
     * Supported types:
     * - 'tar'
     * - 'tgz'
     * - 'zip'
     *
     * @param string $s3file
     *
     * @return string
     */
    private function detectBundleType($s3file)
    {
        $supported = [
            '.zip' => 'zip',
            '.tgz' => 'tgz',
            '.tar.gz' => 'tgz',
        ];

        foreach ($supported as $extension => $bundleType) {
            if (1 === preg_match('/' .  preg_quote($extension) . '$/', $s3file)) {
                return $bundleType;
            }
        }

        // fall back to "tgz"
        return 'tgz';
    }
}
