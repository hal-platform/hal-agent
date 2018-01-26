<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\CodeDeploy;

use Aws\CodeDeploy\CodeDeployClient;
use Aws\S3\S3Client;
use Hal\Agent\Push\DeployerInterface;
use Hal\Agent\Push\ReleasePacker;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\OutputAwareInterface;
use Hal\Agent\Symfony\OutputAwareTrait;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Type\GroupEnum;

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
     * @var EventLogger
     */
    private $logger;

    /**
     * @var AWSAuthenticator
     */
    private $authenticator;

    /**
     * @var HealthChecker
     */
    private $health;

    /**
     * @var ReleasePacker
     */
    private $packer;

    /**
     * @var Uploader
     */
    private $uploader;

    /**
     * @var Pusher
     */
    private $pusher;

    /**
     * @param EventLogger $logger
     * @param AWSAuthenticator $authenticator
     * @param HealthChecker $health
     * @param ReleasePacker $packer
     * @param Uploader $uploader
     * @param Pusher $pusher
     */
    public function __construct(
        EventLogger $logger,
        AWSAuthenticator $authenticator,
        HealthChecker $health,
        ReleasePacker $packer,
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
        if (!isset($properties[GroupEnum::TYPE_CD]) || !$this->verifyConfiguration($properties[GroupEnum::TYPE_CD])) {
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

        $required = [
            // aws
            'region',
            'credential',
            // codedeploy
            'application',
            'configuration',
            'group',
            // s3
            'bucket',
            'file',
            'src'
        ];

        foreach ($required as $prop) {
            if (!array_key_exists($prop, $properties)) {
                return false;
            }
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
            $properties[GroupEnum::TYPE_CD]['region'],
            $properties[GroupEnum::TYPE_CD]['credential']
        );

        if (!$cd) {
            return null;
        }

        $s3 = $this->authenticator->getS3(
            $properties[GroupEnum::TYPE_CD]['region'],
            $properties[GroupEnum::TYPE_CD]['credential']
        );

        if (!$s3) {
            return null;
        }

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
            $properties[GroupEnum::TYPE_CD]['application'],
            $properties[GroupEnum::TYPE_CD]['group']
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

        return $this->packer->packZipOrTar(
            $properties['location']['path'],
            $properties[GroupEnum::TYPE_CD]['src'],
            $properties['location']['tempUploadArchive'],
            $properties[GroupEnum::TYPE_CD]['file']
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

        $release = $properties['release'];
        $build = $properties['build'];
        $environment = $release->target()->group()->environment();

        $metadata = [
            'Build' => $build->id(),
            'Release' => $release->id(),
            'Environment' => $environment->name()
        ];

        $uploader = $this->uploader;
        return $uploader(
            $s3,
            $properties['location']['tempUploadArchive'],
            $properties[GroupEnum::TYPE_CD]['bucket'],
            $properties[GroupEnum::TYPE_CD]['file'],
            $metadata
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

        $release = $properties['release'];
        $build = $properties['build'];
        $environment = $release->target()->group()->environment();

        $pusher = $this->pusher;
        return $pusher(
            $cd,
            $properties[GroupEnum::TYPE_CD]['application'],
            $properties[GroupEnum::TYPE_CD]['group'],
            $properties[GroupEnum::TYPE_CD]['configuration'],
            $properties[GroupEnum::TYPE_CD]['bucket'],
            $properties[GroupEnum::TYPE_CD]['file'],
            $build->id(),
            $release->id(),
            $environment->name()
        );
    }
}
