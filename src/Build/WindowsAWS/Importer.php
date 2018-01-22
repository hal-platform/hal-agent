<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\S3\S3Client;
use Aws\Ssm\SsmClient;
use Hal\Agent\Build\InternalDebugLoggingTrait;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Build\WindowsAWS\AWS\S3Downloader;
use Hal\Agent\Build\WindowsAWS\AWS\SSMCommandRunner;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;

class Importer
{
    use InternalDebugLoggingTrait;

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Import to AWS build server';

    const TIMEOUT_COMMAND = 120;

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Unpacker
     */
    private $unpacker;

    /**
     * @var S3Downloader
     */
    private $downloader;

    /**
     * @var SSMCommandRunner
     */
    private $runner;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var Powershellinator
     */
    private $powershell;

    /**
     * @param EventLogger $logger
     * @param Unpacker $unpacker
     * @param S3Downloader $downloader
     * @param SSMCommandRunner $runner
     * @param ProcessBuilder $processBuilder
     * @param Powershellinator $powershell
     */
    public function __construct(
        EventLogger $logger,
        Unpacker $unpacker,
        S3Downloader $downloader,
        SSMCommandRunner $runner,
        ProcessBuilder $processBuilder,
        Powershellinator $powershell
    ) {
        $this->logger = $logger;

        $this->unpacker = $unpacker;
        $this->downloader = $downloader;
        $this->runner = $runner;

        $this->processBuilder = $processBuilder;
        $this->powershell = $powershell;
    }

    /**
     * @param S3Client $s3
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @param string $buildPath
     * @param string $buildFile
     * @param string $bucket
     * @param string $object
     * @param string $buildID
     *
     * @return bool
     */
    public function __invoke(S3Client $s3, SsmClient $ssm, $instanceID, $buildPath, $buildFile, $bucket, $object, $buildID)
    {
        // $instanceID

        if (!$this->packAWSFile($ssm, $instanceID, $bucket, $object, $buildID)) {
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

        $runner = $this->runner;
        $result = $runner($ssm, $instanceID, SSMCommandRunner::TYPE_POWERSHELL, [
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
        ], [$this->isDebugLoggingEnabled()]);

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
        $downloader = $this->downloader;
        return $downloader(
            $s3,
            $bucket,
            $object,
            $localFile
        );
    }

    /**
     * @param string $buildPath
     *
     * @return bool
     */
    private function removeLocalFiles($buildPath)
    {
        // remove local build dir
        $command = ['rm', '-r', $buildPath];
        $process = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($command)
            ->getProcess();

        $process->run();

        # we don't care if this fails
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
        $unpacker = $this->unpacker;

        return $unpacker($buildFile, $buildPath);
    }
}
