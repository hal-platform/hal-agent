<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Artifact\Steps;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ProcessBuilder;

// @TODO: Refactor this (look at Job\FileCompression)
class Compressor
{
    use ProcessRunnerTrait;

    const EVENT_MESSAGE = 'Pack deployment into revision archive';

    const ERR_TIMEOUT = 'Packing the revision archive took too long';
    const ERR_DIST_NOT_FOUND = 'Release directory not found';
    const ERR_DIST_NOT_VALID = 'Invalid release directory specified';
    const ERR_CANNOT_AUTODETECT = 'Cannot auto-detect archive method';

    // For AWS, deref hardlinks when creating tarball "-h"
    const TAR_FLAGS = '-hvczf';
    const ZIP_FLAGS = '--recurse-paths';

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
     * Auto-detect the packing method from the destination file extension.
     *
     * Really only needed for S3/CD since those support zip and tar.
     *
     * @param string $buildPath
     * @param string $distPath
     * @param string $targetFile
     *
     * @return bool
     */
    public function packZipOrTar($buildPath, $distPath, $targetFile, $destFile)
    {
        $supported = [
            '.zip' => [$this, 'packZip'],
            '.tgz' => [$this, 'packTar'],
            '.tar.gz' => [$this, 'packTar'],
        ];

        foreach ($supported as $extension => $packer) {
            if (1 === preg_match('/' .  preg_quote($extension) . '$/', $destFile)) {
                return $packer($buildPath, $distPath, $targetFile);
            }
        }

        $this->logger->event('failure', self::ERR_CANNOT_AUTODETECT, [
            'path' => $distPath,
            'supportedArchiveMethods' => array_keys($supported)
        ]);

        // fail if can't find a method
        return false;
    }

    /**
     * @param string $buildPath
     * @param string $distPath
     * @param string $targetFile
     *
     * @return bool
     */
    public function packZip($buildPath, $distPath, $targetFile)
    {
        $distPath = trim($distPath, DIRECTORY_SEPARATOR);
        $wholePath = rtrim($buildPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $distPath;

        $workingPath = $buildPath;
        if ($distPath !== '.') {
            $workingPath = $wholePath;
        }

        if (!$this->verify($distPath, $wholePath)) {
            return false;
        }

        $this->relocateHalConfig($buildPath, $wholePath);

        // Always zip "." so that we dont store the fully qualified paths in the archive.
        // Instead, we change the working directory of the dir we want to pack up.
        $zipCommand = ['zip', static::ZIP_FLAGS, $targetFile, '.'];

        return $this->pack($workingPath, $zipCommand);
    }

    /**
     * @param string $buildPath
     * @param string $distPath
     * @param string $targetFile
     *
     * @return bool
     */
    public function packTar($buildPath, $distPath, $targetFile)
    {
        $distPath = trim($distPath, DIRECTORY_SEPARATOR);
        $wholePath = rtrim($buildPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $distPath;

        $workingPath = $buildPath;
        if ($distPath !== '.') {
            $workingPath = $wholePath;
        }

        if (!$this->verify($distPath, $wholePath)) {
            return false;
        }

        $this->relocateHalConfig($buildPath, $wholePath);

        // Always tar "." so that we dont store the fully qualified paths in the archive.
        // Instead, we change the working directory of the dir we want to pack up.
        $tarCommand = ['tar', static::TAR_FLAGS, $targetFile, '.'];

        return $this->pack($workingPath, $tarCommand);
    }

    /**
     * @param string $distPath
     * @param string $wholePath
     *
     * @return bool
     */
    private function verify($distPath, $wholePath)
    {
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

        return true;
    }

    /**
     * Move .hal9000.yml file to dist if present
     *
     * @param string $buildPath
     * @param string $wholePath
     *
     * @return void
     */
    private function relocateHalConfig($buildPath, $wholePath)
    {
        $halSource = $buildPath . '/.hal9000.yml';
        $halTarget = $wholePath . '/.hal9000.yml';

        if ($this->filesystem->exists($halSource) && !$this->filesystem->exists($halTarget)) {
            $this->filesystem->copy($halSource, $halTarget, true);
        }
    }

    /**
     * @param string $workingPath
     * @param array $command
     *
     * @return bool
     */
    private function pack($workingPath, array $command)
    {
        $process = $this->processBuilder
            ->setWorkingDirectory($workingPath)
            ->setArguments($command)
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        if (!$this->runProcess($process, $this->commandTimeout)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        $dispCommand = implode(' ', $command);
        return $this->processFailure($dispCommand, $process);
    }
}
