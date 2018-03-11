<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3;

use Aws\S3\S3Client;
use Hal\Agent\AWS\S3Uploader;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Deploy\PlatformTrait;
use Hal\Agent\Deploy\S3\Steps\Compressor;
use Hal\Agent\Deploy\S3\Steps\Configurator;
use Hal\Agent\Deploy\S3\Steps\SyncUploader;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\JobExecution;
use Hal\Agent\JobPlatformInterface;
use Hal\Agent\Symfony\IOAwareInterface;
use Hal\Agent\Symfony\IOAwareTrait;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Release;

class S3DeployPlatform implements IOAwareInterface, JobPlatformInterface
{
    use FormatterTrait;
    // Comes with IOAwareTrait
    use PlatformTrait;

    private const STEP_1_CONFIGURING = 'S3 Platform - Validating S3 configuration';
    private const STEP_2_COMPRESSING = 'S3 Platform - Compressing source';
    private const STEP_3_UPLOADING = 'S3 Platform - Uploading artifacts to S3 bucket';

    private const NOTE_SKIP_COMPRESSION = 'Skipping compression step in sync mode';

    private const ERR_INVALID_JOB = 'The provided job is an invalid type for this job platform';
    private const ERR_CONFIGURATOR = 'S3 deploy platform is not configured correctly';
    private const ERR_COMPRESSOR = 'The source directory could not be compressed';
    private const ERR_UPLOADER = 'The artifact(s) could not be uploaded to the S3 Bucket';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Configurator
     */
    private $configurator;

    /**
     * @var Compressor
     */
    private $compressor;

    /**
     * @var S3Uploader
     */
    private $artifactUploader;

    /**
     * @var SyncUploader
     */
    private $syncUploader;

    /**
     * @param EventLogger $logger
     * @param Configurator $configurator
     * @param Compressor $compressor
     * @param S3Uploader $artifactUploader
     * @param SyncUploader $syncUploader
     */
    public function __construct(
        EventLogger $logger,
        Configurator $configurator,
        Compressor $compressor,
        S3Uploader $artifactUploader,
        SyncUploader $syncUploader
    ) {
        $this->logger = $logger;

        $this->configurator = $configurator;
        $this->compressor = $compressor;

        $this->artifactUploader = $artifactUploader;
        $this->syncUploader = $syncUploader;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(Job $job, JobExecution $execution, array $properties): bool
    {
        if (!$job instanceof Release) {
            $this->sendFailureEvent(self::ERR_INVALID_JOB);
            return false;
        }

        if (!$platformConfig = $this->configurator($job)) {
            $this->sendFailureEvent(self::ERR_CONFIGURATOR);
            return false;
        }

        if (!$this->compressor($properties['workspace_path'], $platformConfig)) {
            $this->sendFailureEvent(self::ERR_COMPRESSOR);
            return false;
        }

        if (!$this->uploader($job, $properties['workspace_path'], $platformConfig)) {
            $this->sendFailureEvent(self::ERR_UPLOADER);
            return false;
        }

        return true;
    }

    /**
     * @param Job $job
     *
     * @return array|null
     */
    private function configurator(Job $job)
    {
        $this->getIO()->section(self::STEP_1_CONFIGURING);

        $platformConfig = ($this->configurator)($job);

        if (!$platformConfig) {
            return null;
        }

        $this->outputTable($this->getIO(), 'Platform configuration:', $platformConfig);

        return $platformConfig;
    }

    /**
     * @param string $workspacePath
     * @param array $platformConfig
     *
     * @return bool
     */
    private function compressor($workspacePath, array $platformConfig)
    {
        $this->getIO()->section(self::STEP_2_COMPRESSING);

        $wholeSourcePath = $workspacePath . '/job/' . $platformConfig['local_path'];
        $tempArtifactFile = $workspacePath . '/build_export.compressed';
        $remotePath = $platformConfig['remote_path'];

        if ($platformConfig['method'] === 'sync') {
            $this->getIO()->note(self::NOTE_SKIP_COMPRESSION);
            return true;
        }

        $this->getIO()->listing([
            sprintf('Local Path: <info>%s</info>', $wholeSourcePath),
            sprintf('Temp Artifact: <info>%s</info>', $tempArtifactFile)
        ]);

        if ($platformConfig['method'] === 'artifact') {
            return ($this->compressor)($wholeSourcePath, $tempArtifactFile, $remotePath);
        }

        return false;
    }

    /**
     * @param Release $release
     * @param string $workspacePath
     * @param array $platformConfig
     *
     * @return bool
     */
    private function uploader(Release $release, $workspacePath, array $platformConfig)
    {
        $this->getIO()->section(self::STEP_3_UPLOADING);

        $method = $platformConfig['method'];
        $s3 = $platformConfig['sdk']['s3'];

        if ($method === 'sync') {
            $sourcePath = $workspacePath . '/job/' . $platformConfig['local_path'];
            return ($this->syncUploader)(
                $s3,
                $sourcePath,
                $platformConfig['bucket'],
                $platformConfig['remote_path']
            );

        } elseif ($method === 'artifact') {
            $sourceFile = $workspacePath . '/build_export.compressed';
            return ($this->artifactUploader)(
                $s3,
                $sourceFile,
                $platformConfig['bucket'],
                $platformConfig['remote_path'],
                [
                    'Job' => $release->id(),
                    'Environment' => $release->environment()->name()
                ]
            );
        }

        return false;
    }

    /**
     * @param string $message
     *
     * @return void
     */
    private function sendFailureEvent($message)
    {
        $this->logger->event('failure', $message);
        $this->getIO()->error($message);
    }
}
