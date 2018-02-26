<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3\Sync;

use Aws\S3\S3Client;
use Hal\Agent\Push\DeployerInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\OutputAwareInterface;
use Hal\Agent\Symfony\OutputAwareTrait;
use Hal\Agent\Utility\SourcePathBuilderTrait;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Type\TargetEnum;

class Deployer implements DeployerInterface, OutputAwareInterface
{
    use OutputAwareTrait;
    use SourcePathBuilderTrait;

    const SECTION = 'Deploying - S3 - Sync';
    const STATUS = 'Deploying push by S3';

    const ERR_INVALID_DEPLOYMENT_SYSTEM = 'S3 deployment system is not configured';

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

        // upload version to S3
        if (!$this->upload($s3, $properties)) {
            return 403;
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
            $properties[TargetEnum::TYPE_S3]['region'],
            $properties[TargetEnum::TYPE_S3]['credential']
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
     * @return boolean
     */
    private function prepareFile(array $properties)
    {
        $this->status('Packing build for S3', self::SECTION);

        $preparer = $this->preparer;
        return $preparer(
            $properties['location']['path'],
            $properties[TargetEnum::TYPE_S3]['src']
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

        $release = $properties['release'];
        $build = $properties['release']->build();
        $environment = $build->environment();

        $metadata = [
            'Build' => $build->id(),
            'Push' => $release->id(),
            'Environment' => $environment->name()
        ];

        $wholeSourcePath = $this->getWholeSourcePath($properties['location']['path'], $properties[TargetEnum::TYPE_S3]['src']);

        $uploader = $this->uploader;
        return $uploader(
            $s3,
            $wholeSourcePath,
            $properties[TargetEnum::TYPE_S3]['bucket'],
            $properties[TargetEnum::TYPE_S3]['file'],
            $metadata
        );
    }
}
