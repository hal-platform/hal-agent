<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3\Artifact;

use Aws\S3\S3Client;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Push\DeployerInterface;
use Hal\Agent\Symfony\OutputAwareInterface;
use Hal\Agent\Symfony\OutputAwareTrait;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Type\GroupEnum;

class Deployer implements DeployerInterface, OutputAwareInterface
{
    use OutputAwareTrait;

    const SECTION = 'Deploying - S3 - Artifact';
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
     * @var Preparer
     */
    private $preparer;

    /**
     * @var Uploader
     */
    private $uploader;

    /**
     * @param EventLogger $logger
     * @param AWSAuthenticator $authenticator
     * @param Preparer $preparer
     * @param Uploader $uploader
     */
    public function __construct(
        EventLogger $logger,
        AWSAuthenticator $authenticator,
        Preparer $preparer,
        Uploader $uploader
    ) {
        $this->logger = $logger;

        $this->authenticator = $authenticator;
        $this->preparer = $preparer;
        $this->uploader = $uploader;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(array $properties)
    {
        $this->status(self::STATUS, self::SECTION);

        // authenticate
        if (!$s3 = $this->authenticate($properties)) {
            return 401;
        }

        // Prepare upload file - move or create archive
        if (!$this->prepareFile($properties)) {
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
     * @return S3Client|null
     */
    private function authenticate(array $properties)
    {
        $this->status('Authenticating with AWS', self::SECTION);

        return $this->authenticator->getS3(
            $properties[GroupEnum::TYPE_S3]['region'],
            $properties[GroupEnum::TYPE_S3]['credential']
        );
    }

    /**
     *  Supported situations:
     *
     * - src_file : file(.zip|.tgz|.tar.gz)   OK (just upload)
     * - src_file : file                      OK (just upload)
     *
     * - src_dir  : file(.zip|.tgz|.tar.gz)   OK (pack with zip or tar)
     * - src_dir  : file                      OK (default to tgz)
     *
     * @param array $properties
     *
     * @return bool
     */
    private function prepareFile(array $properties)
    {
        $this->status('Packing build for S3', self::SECTION);

        $preparer = $this->preparer;

        return $preparer(
            $properties['location']['path'],
            $properties[GroupEnum::TYPE_S3]['src'],
            $properties['location']['tempUploadArchive'],
            $properties[GroupEnum::TYPE_S3]['file']
        );
    }

    /**
     * @param S3Client $s3
     * @param array $properties
     *
     * @return bool
     */
    private function upload(S3Client $s3, array $properties)
    {
        $this->status('Uploading to S3', self::SECTION);

        $release = $properties['release'];
        $build = $properties['release']->build();
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
            $properties[GroupEnum::TYPE_S3]['bucket'],
            $properties[GroupEnum::TYPE_S3]['file'],
            $metadata
        );
    }
}
