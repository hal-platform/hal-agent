<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ProcessBuilder;

/**
 *
 * TODO REMOVE
 * TODO REMOVE
 * TODO REMOVE    - This was combined into FileCompression and Artifacter
 * TODO REMOVE
 * TODO REMOVE
 *
 */
class Packer
{
    use ProcessRunnerTrait;

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Archive build';
    const ERR_TIMEOUT = 'Archiving the build took too long';

    const ERR_DIST_NOT_FOUND = 'Distribution directory not found';
    const ERR_DIST_NOT_VALID = 'Invalid distribution directory specified';

    const TAR_FLAGS = '-vczf';
    const MB_IN_BYTES = 1048576;

    /**
     * @var EventLogger
     */
    private $logger;

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
     * @var bool
     */
    private $logOnSuccess;

    /**
     * @param EventLogger $logger
     * @param Filesystem $filesystem
     * @param ProcessBuilder $processBuilder
     * @param string $commandTimeout
     */
    public function __construct(EventLogger $logger, Filesystem $filesystem, ProcessBuilder $processBuilder, $commandTimeout)
    {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->processBuilder = $processBuilder;
        $this->commandTimeout = $commandTimeout;

        $this->logOnSuccess = true;
    }

    /**
     * @return void
     */
    public function disableForcedLogging()
    {
        $this->logOnSuccess = false;
    }

    /**
     * @param string $buildPath
     * @param string $distPath
     * @param string $targetFile
     * @return boolean
     */
    public function __invoke($buildPath, $distPath, $targetFile)
    {
        $distPath = trim($distPath, DIRECTORY_SEPARATOR);
        $wholePath = rtrim($buildPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $distPath;

        // Do not allow dir traversal. Dist path must be within build dir
        if (stripos($wholePath, '../') !== false) {
            $this->logger->event('failure', self::ERR_DIST_NOT_VALID, ['path' => $distPath]);
            return false;
        }

        // dist does not exist
        if (!$distPath || !$this->filesystem->exists($wholePath)) {
            $this->logger->event('failure', self::ERR_DIST_NOT_FOUND, ['path' => $distPath]);
            return false;
        }

        // move .hal9000.yml file to dist if present
        $halSource = $buildPath . '/.hal9000.yml';
        $halTarget = $wholePath . '/.hal9000.yml';
        if ($this->filesystem->exists($halSource) && !$this->filesystem->exists($halTarget)) {
            $this->filesystem->copy($halSource, $halTarget, true);
        }

        $workingPath = $buildPath;
        if ($distPath !== '.') {
            $workingPath = $wholePath;
        }

        $tarCommand = ['tar', static::TAR_FLAGS, $targetFile, '.'];
        $process = $this->processBuilder
            ->setWorkingDirectory($workingPath)
            ->setArguments($tarCommand)
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        if (!$this->runProcess($process, $this->commandTimeout)) {
            return false;
        }

        if ($process->isSuccessful()) {
            if ($this->logOnSuccess) {
                $this->logSuccess($targetFile);
            }

            return true;
        }

        $dispCommand = implode(' ', $tarCommand);
        return $this->processFailure($dispCommand, $process);
    }

    /**
     * @param string $targetFile
     *
     * @return void
     */
    private function logSuccess($targetFile)
    {
        $filesize = filesize($targetFile);

        $this->logger->event('success', static::EVENT_MESSAGE, [
            'size' => sprintf('%s MB', round($filesize / self::MB_IN_BYTES, 2))
        ]);
    }
}
