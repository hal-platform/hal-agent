<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\CodeDeploy;

use Aws\CodeDeploy\CodeDeployClient;
use Aws\S3\S3Client;
use QL\Hal\Agent\Push\AWSAuthenticator;
use QL\Hal\Agent\Push\DeployerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
use QL\Hal\Core\Type\EnumType\ServerEnum;

/**
 * @see http://docs.aws.amazon.com/codedeploy/latest/userguide/welcome.html
 * @see http://docs.aws.amazon.com/aws-sdk-php/v3/api/api-codedeploy-2014-10-06.html
 */
class Deployer implements DeployerInterface, OutputAwareInterface
{
    use OutputAwareTrait;

    const SECTION = 'Deploying - CodeDeploy';
    const STATUS = 'Deploying push by CodeDeploy';

    const ERR_INVALID_DEPLOYMENT_SYSTEM = 'CodeDeploy deployment system is not configured';
    const ERR_HEALTH = 'CodeDeploy environment is not available';

    const SKIP_PRE_PUSH = 'Skipping pre-push commands for CodeDeploy deployment';
    const SKIP_POST_PUSH = 'Skipping post-push commands for CodeDeploy deployment';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type AWSAuthenticator
     */
    private $authenticator;

    /**
     * @type HealthChecker
     */
    private $health;

    /**
     * @type Packer
     */
    private $packer;

    /**
     * @type Uploader
     */
    private $uploader;

    /**
     * @type Pusher
     */
    private $pusher;

    /**
     * @param EventLogger $logger
     * @param AWSAuthenticator $authenticator
     * @param HealthChecker $health
     * @param Packer $packer
     * @param Uploader $uploader
     * @param Pusher $pusher
     */
    public function __construct(
        EventLogger $logger,
        AWSAuthenticator $authenticator,
        HealthChecker $health,
        Packer $packer,
        Uploader $uploader,
        Pusher $pusher
    ) {
        $this->logger = $logger;
        $this->authenticator = $authenticator;

        $this->health = $health;
        $this->packer = $packer;
        $this->uploader = $uploader;
        $this->pusher = $pusher;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(array $properties)
    {
        $this->status(self::STATUS, self::SECTION);

        // sanity check
        if (!isset($properties[ServerEnum::TYPE_CD]) || !$this->verifyConfiguration($properties[ServerEnum::TYPE_CD])) {
            $this->logger->event('failure', self::ERR_INVALID_DEPLOYMENT_SYSTEM);
            return 500;
        }

        // authenticate
        if (!$clients = $this->authenticate($properties)) {
            return 501;
        }

        list($cd, $s3) = $clients;

        // CodeDeploy will actually prevent concurrent deploys itself, but this is just a sanity check
        // for other conditions, and allow us to be more specific in our error message/details
        // - bad app name
        // - bad group name
        // - unhealthy or in progress deployment
        if (!$this->health($cd, $properties)) {
            return 502;
        }

        // create tarball for s3
        if (!$this->pack($properties)) {
            return 503;
        }

        // SKIP pre-push commands
        if ($properties['configuration']['pre_push']) {
            $this->logger->event('info', self::SKIP_PRE_PUSH);
        }

        // upload version to S3
        if (!$this->upload($s3, $properties)) {
            return 504;
        }

        // push
        if (!$this->push($cd, $properties)) {
            return 505;
        }

        // SKIP post-push commands
        if ($properties['configuration']['post_push']) {
            $this->logger->event('info', self::SKIP_POST_PUSH);
        }

        // success
        return 0;
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function verifyConfiguration($properties)
    {
        $this->status('Verifying CodeDeploy configuration', self::SECTION);

        if (!is_array($properties)) {
            return false;
        }

        if (!array_key_exists('region', $properties)) {
            return false;
        }

        if (!array_key_exists('credential', $properties)) {
            return false;
        }

        if (!array_key_exists('application', $properties)) {
            return false;
        }

        if (!array_key_exists('configuration', $properties)) {
            return false;
        }

        if (!array_key_exists('group', $properties)) {
            return false;
        }

        if (!array_key_exists('bucket', $properties)) {
            return false;
        }

        if (!array_key_exists('file', $properties)) {
            return false;
        }

        return true;
    }

    /**
     * @param array $properties
     *
     * @return array|null
     */
    private function authenticate(array $properties)
    {
        $this->status('Authenticating with AWS', self::SECTION);

        $cd = $this->authenticator->getCD(
            $properties[ServerEnum::TYPE_CD]['region'],
            $properties[ServerEnum::TYPE_CD]['credential']
        );

        if (!$cd) return null;

        $s3 = $this->authenticator->getS3(
            $properties[ServerEnum::TYPE_CD]['region'],
            $properties[ServerEnum::TYPE_CD]['credential']
        );

        if (!$s3) return null;

        return [$cd, $s3];
    }

    /**
     * @param CodeDeployClient $cd
     * @param array $properties
     *
     * @return boolean
     */
    private function health(CodeDeployClient $cd, array $properties)
    {
        $this->status('Checking AWS CodeDeploy health', self::SECTION);

        $health = $this->health;
        $health = $health(
            $cd,
            $properties[ServerEnum::TYPE_CD]['application'],
            $properties[ServerEnum::TYPE_CD]['group']
        );

        if (!in_array($health['status'], ['Succeeded', 'Failed', 'Stopped', 'None'])) {
            $this->logger->event('failure', self::ERR_HEALTH, $health);
            return false;
        }

        return true;
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function pack(array $properties)
    {
        $this->status('Packing build for S3', self::SECTION);

        $packer = $this->packer;
        return $packer(
            $properties['location']['path'],
            '.',
            $properties['location']['tempTarArchive']
        );
    }

    /**
     * @param S3Client $s3
     * @param array $properties
     *
     * @return boolean
     */
    private function upload(S3Client $s3, array $properties)
    {
        $this->status('Pushing code to S3', self::SECTION);

        $push = $properties['push'];
        $build = $properties['build'];
        $environment = $build->environment();

        $uploader = $this->uploader;
        return $uploader(
            $s3,
            $properties['location']['tempTarArchive'],
            $properties[ServerEnum::TYPE_CD]['bucket'],
            $properties[ServerEnum::TYPE_CD]['file'],
            $build->id(),
            $push->id(),
            $environment->name()
        );
    }

    /**
     * @param CodeDeployClient $cd
     * @param array $properties
     *
     * @return boolean
     */
    private function push(CodeDeployClient $cd, array $properties)
    {
        $this->status('Deploying version to CodeDeploy', self::SECTION);

        $push = $properties['push'];
        $build = $properties['build'];
        $environment = $build->environment();

        $pusher = $this->pusher;
        return $pusher(
            $cd,
            $properties[ServerEnum::TYPE_CD]['application'],
            $properties[ServerEnum::TYPE_CD]['group'],
            $properties[ServerEnum::TYPE_CD]['configuration'],
            $properties[ServerEnum::TYPE_CD]['bucket'],
            $properties[ServerEnum::TYPE_CD]['file'],
            $build->id(),
            $push->id(),
            $environment->name()
        );
    }
}
