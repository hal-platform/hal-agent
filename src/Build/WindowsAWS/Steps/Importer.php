<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS\Steps;

use Aws\S3\S3Client;
use Aws\Ssm\SsmClient;
use Hal\Agent\Build\InternalDebugLoggingTrait;
use Hal\Agent\Job\FileCompression;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Build\WindowsAWS\AWS\S3Downloader;
use Hal\Agent\Build\WindowsAWS\AWS\SSMCommandRunner;
use Symfony\Component\Filesystem\Filesystem;
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;

class Importer
{
    use InternalDebugLoggingTrait;

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Import from AWS build server';

    const TIMEOUT_COMMAND = 120;

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var FileCompression
     */
    private $fileCompression;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var S3Downloader
     */
    private $downloader;

    /**
     * @var SSMCommandRunner
     */
    private $runner;

    /**
     * @var Powershellinator
     */
    private $powershell;

    /**
     * @param EventLogger $logger
     * @param FileCompression $fileCompression
     * @param Filesystem $filesystem
     * @param S3Downloader $downloader
     * @param SSMCommandRunner $runner
     * @param Powershellinator $powershell
     */
    public function __construct(
        EventLogger $logger,
        FileCompression $fileCompression,
        Filesystem $filesystem,
        S3Downloader $downloader,
        SSMCommandRunner $runner,
        Powershellinator $powershell
    ) {
        $this->logger = $logger;
        $this->fileCompression = $fileCompression;
        $this->filesystem = $filesystem;

        $this->downloader = $downloader;
        $this->runner = $runner;

        $this->powershell = $powershell;
    }

    /**
     * @param S3Client $s3
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @param string $jobID
     *
     * @param string $buildPath
     * @param string $buildFile
     * @param string $bucket
     * @param string $object
     *
     * @return bool
     */
    public function __invoke(S3Client $s3, SsmClient $ssm, $instanceID, $jobID, $buildPath, $buildFile, $bucket, $object)
    {
        if (!$this->packAWSFile($ssm, $instanceID, $bucket, $object, $jobID)) {
            return false;
        }

        if (!$this->transferFile($s3, $bucket, $object, $buildFile)) {
            return false;
        }

        if (!$this->removeLocalFiles($buildPath)) {
            return false;
        }

        if (!$this->unpackBuild($buildFile, $buildPath)) {
            return false;
        }

        return true;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @param string $bucket
     * @param string $object
     * @param string $buildID
     *
     * @return bool
     */
    private function packAWSFile(SsmClient $ssm, $instanceID, $bucket, $object, $buildID)
    {
        $workDir = $this->powershell->getBaseBuildPath();

        $outputDir = "${workDir}\\${buildID}-output";
        $localFile = "${workDir}\\${buildID}.tar.gz";

        $config = [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('tarBuild', [
                    'localFile' => $localFile,
                    'buildDir' => $outputDir
                ]),
                $this->powershell->getScript('uploadBuild', [
                    'localFile' => $localFile,
                    'bucket' => $bucket,
                    'object' => $object,
                ])
            ],
            'workingDirectory' => [$workDir],
            'executionTimeout' => [(string) self::TIMEOUT_COMMAND],
        ];

        $result = ($this->runner)(
            $ssm,
            $instanceID,
            SSMCommandRunner::TYPE_POWERSHELL,
            $config,
            [$this->isDebugLoggingEnabled(), self::EVENT_MESSAGE]
        );

        return $result;
    }

    /**
     * @param S3Client $s3
     * @param string $bucket
     * @param string $object
     * @param string $localFile
     *
     * @return bool
     */
    private function transferFile(S3Client $s3, $bucket, $object, $localFile)
    {
        return ($this->downloader)($s3, $bucket, $object, $localFile);
    }

    /**
     * @param string $buildPath
     *
     * @return bool
     */
    private function removeLocalFiles($buildPath)
    {
        if ($this->filesystem->exists($buildPath)) {
            $this->filesystem->remove($buildPath);
        }

        return true;
    }

    /**
     * @param string $buildFile
     * @param string $buildPath
     *
     * @return bool
     */
    private function unpackBuild($buildFile, $buildPath)
    {
        $this->fileCompression->createWorkspace($buildPath);

        if (!$this->fileCompression->unpackTarArchive($buildPath, $buildFile)) {
            return false;
        }

        return true;
    }
}
