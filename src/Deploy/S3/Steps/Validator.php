<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Aws\S3\S3Client;
use Hal\Agent\Deploy\DeployException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class Validator
{
    const ERR_TIMEOUT = 'Validating files to upload took too long';

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var string
     */
    private $commandTimeout;

    /**
     * @param Filesystem $filesystem
     * @param ProcessBuilder $processBuilder
     * @param string $commandTimeout
     */
    public function __construct(
        Filesystem $filesystem,
        ProcessBuilder $processBuilder,
        $commandTimeout
    ) {
        $this->filesystem = $filesystem;
        $this->processBuilder = $processBuilder;

        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $sourcePath
     *
     * @return bool
     */
    public function localPathExists($sourcePath): bool
    {
        return $this->filesystem->exists($sourcePath);
    }

    /**
     * @param string $sourcePath
     *
     * @throws DeployException
     *
     * @return bool
     */
    public function isDirectory($sourcePath): bool
    {
        // check if dir
        $isDirCommand = ['test', '-d', $sourcePath];
        $process = $this->processBuilder
            ->setArguments($isDirCommand)
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new DeployException(self::ERR_TIMEOUT);
        }

        return $process->isSuccessful();
    }

    /**
     * @param S3Client $s3
     * @param string $bucket
     *
     * @return bool
     */
    public function bucketExists(S3Client $s3, string $bucket): bool
    {
        return $s3->doesBucketExist($bucket);
    }
}
