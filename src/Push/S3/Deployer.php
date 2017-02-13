<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3;

use Aws\S3\S3Client;
use Hal\Agent\Push\AWSAuthenticator;
use Hal\Agent\Push\DeployerInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\OutputAwareInterface;
use Hal\Agent\Symfony\OutputAwareTrait;
use QL\Hal\Core\Type\EnumType\ServerEnum;

class Deployer implements DeployerInterface, OutputAwareInterface
{
    use OutputAwareTrait;

    const SECTION = 'Deploying - S3';
    const STATUS = 'Deploying push by S3';

    const ERR_INVALID_DEPLOYMENT_SYSTEM = 'S3 deployment system is not configured';

    const SKIP_PRE_PUSH = 'Skipping pre-push commands for S3 deployment';
    const SKIP_POST_PUSH = 'Skipping post-push commands for S3 deployment';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var AWSAuthenticator
     */
    private $authenticator;

    /**
     * @var Packer
     */
    private $packer;

    /**
     * @var Uploader
     */
    private $uploader;

    /**
     * @param EventLogger $logger
     * @param AWSAuthenticator $authenticator
     * @param Packer $packer
     * @param Uploader $uploader
     */
    public function __construct(
        EventLogger $logger,
        AWSAuthenticator $authenticator,
        Packer $packer,
        Uploader $uploader
    ) {
        $this->logger = $logger;

        $this->authenticator = $authenticator;
        $this->packer = $packer;
        $this->uploader = $uploader;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(array $properties)
    {
        $this->status(self::STATUS, self::SECTION);

        // sanity check
        if (!isset($properties[ServerEnum::TYPE_S3]) || !$this->verifyConfiguration($properties[ServerEnum::TYPE_S3])) {
            $this->logger->event('failure', self::ERR_INVALID_DEPLOYMENT_SYSTEM);
            return 400;
        }

        // authenticate
        if (!$s3 = $this->authenticate($properties)) {
            return 401;
        }

        // create zip for s3
        if (!$this->pack($properties)) {
            return 402;
        }

        // SKIP pre-push commands
        if ($properties['configuration']['pre_push']) {
            $this->logger->event('info', self::SKIP_PRE_PUSH);
        }

        // upload version to S3
        if (!$this->upload($s3, $properties)) {
            return 403;
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
        $this->status('Verifying S3 configuration', self::SECTION);

        if (!is_array($properties)) {
            return false;
        }

        if (!array_key_exists('region', $properties)) {
            return false;
        }

        if (!array_key_exists('credential', $properties)) {
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
     * @return S3Client|null
     */
    private function authenticate(array $properties)
    {
        $this->status('Authenticating with AWS', self::SECTION);

        return $this->authenticator->getS3(
            $properties[ServerEnum::TYPE_S3]['region'],
            $properties[ServerEnum::TYPE_S3]['credential']
        );
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
        $this->status('Uploading to S3', self::SECTION);

        $push = $properties['push'];
        $build = $properties['push']->build();
        $environment = $build->environment();

        $uploader = $this->uploader;
        return $uploader(
            $s3,
            $properties['location']['tempTarArchive'],
            $properties[ServerEnum::TYPE_S3]['bucket'],
            $properties[ServerEnum::TYPE_S3]['file'],
            $build->id(),
            $push->id(),
            $environment->name()
        );
    }
}
