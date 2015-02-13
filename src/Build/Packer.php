<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ProcessBuilder;

class Packer
{
    use ProcessRunnerTrait;

    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Archive build';
    const ERR_TIMEOUT = 'Archiving the build took too long';

    const ERR_DIST_NOT_FOUND = 'Distribution directory not found';
    const ERR_DIST_NOT_VALID = 'Invalid distribution directory specified';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type Filesystem
     */
    private $filesystem;

    /**
     * @type ProcessBuilder
     */
    private $processBuilder;

    /**
     * @type string
     */
    private $commandTimeout;

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

        $tarCommand = ['tar', '-vczf', $targetFile, '.'];
        $process = $this->processBuilder
            ->setWorkingDirectory($workingPath)
            ->setArguments($tarCommand)
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        if (!$this->runProcess($process, $this->commandTimeout)) {
            return false;
        }

        if ($process->isSuccessful()) {

            $filesize = filesize($targetFile);

            $this->logger->keep('filesize', ['archive' => $filesize]);
            $this->logger->event('success', self::EVENT_MESSAGE, [
                'size' => sprintf('%s MB', round($filesize / 1048576, 2))
            ]);

            return true;
        }

        $dispCommand = implode(' ', $tarCommand);
        return $this->processFailure($dispCommand, $process);
    }
}
