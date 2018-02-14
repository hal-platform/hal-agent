<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Job\FileCompression;
use Hal\Agent\Logger\EventLogger;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class Artifacter
{
    private const EVENT_MESSAGE = 'Store artifact in artifact repository';

    private const ERR_DIST_NOT_FOUND = 'Distribution directory not found';
    private const ERR_DIST_NOT_VALID = 'Invalid distribution directory specified';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var FileCompression
     */
    private $fileCompression;

    /**
     * @var array
     */
    private $fileLocations;

    /**
     * @param EventLogger $logger
     * @param Filesystem $filesystem
     * @param FileCompression $fileCompression
     */
    public function __construct(EventLogger $logger, Filesystem $filesystem, FileCompression $fileCompression)
    {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->fileCompression = $fileCompression;

        $this->fileLocations = [
            '.hal.yaml',
            '.hal.yml',
            '.hal/config.yml',
            '.hal/config.yaml',
            '.hal9000.yml',
        ];
    }

    /**
     * @param array $files
     *
     * @return void
     */
    public function setValidConfigurationLocations(array $files)
    {
        $this->fileLocations = $files;
    }

    /**
     * @param string $buildPath
     * @param string $distPath
     * @param string $artifactFile
     * @param string $storedArtifactFile
     *
     * @return bool
     */
    public function __invoke(string $buildPath, string $distPath, string $artifactFile, string $storedArtifactFile): bool
    {
        if (!$this->packWorkspaceToArtifact($buildPath, $distPath, $artifactFile)) {
            return false;
        }

        if (!$this->moveArtifactToStorage($artifactFile, $storedArtifactFile)) {
            return false;
        }

        $this->logger->event('success', static::EVENT_MESSAGE);
        return true;
    }

    /**
     * @param string $buildPath
     * @param string $distPath
     * @param string $artifactFile
     *
     * @return bool
     */
    private function packWorkspaceToArtifact($buildPath, $distPath, $artifactFile)
    {
        $distPath = trim($distPath, '/');
        $fqPath = rtrim($buildPath, '/') . '/' . $distPath;

        if (!$this->validateDist($fqPath, $distPath)) {
            return false;
        }

        $this->ensureHalConfigurationIsSaved($buildPath, $fqPath);

        $workingPath = ($distPath === '.') ? $buildPath : $fqPath;

        return $this->fileCompression->packTarArchive($workingPath, $artifactFile);
    }

    /**
     * @param string $artifactFile
     * @param string $permanentFile
     *
     * @return bool
     */
    private function moveArtifactToStorage($artifactFile, $permanentFile)
    {
        if (!$this->filesystem->exists($artifactFile)) {
            $this->logger->event('failure', static::EVENT_MESSAGE);
            return false;
        }

        try {
            $this->filesystem->copy($artifactFile, $permanentFile, true);
        } catch (IOException $e) {
            $this->logger->event('failure', static::EVENT_MESSAGE, [
                'error' => $e->getMessage()
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param string $fqPath
     * @param string $distPath
     *
     * @return bool
     */
    private function validateDist($fqPath, $distPath)
    {
        // Do not allow dir traversal. Dist path must be within build dir
        if (stripos($fqPath, '/..') !== false) {
            $this->logger->event('failure', self::ERR_DIST_NOT_VALID, ['path' => $distPath]);
            return false;
        }

        // dist does not exist
        if (!$distPath || !$this->filesystem->exists($fqPath)) {
            $this->logger->event('failure', self::ERR_DIST_NOT_FOUND, ['path' => $distPath]);
            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     * @param string $fqPath
     *
     * @return void
     */
    private function ensureHalConfigurationIsSaved($buildPath, $fqPath)
    {
        // move .hal.yml file to dist if present
        $halTarget = $fqPath . '/.hal.yaml';

        $sourceConfigFile = $this->findConfiguration($buildPath);
        if (!$sourceConfigFile) {
            return;
        }

        if (!$this->filesystem->exists($halTarget)) {
            $this->filesystem->copy($sourceConfigFile, $halTarget, true);
        }
    }

    /**
     * @param string $buildPath
     *
     * @return string
     */
    private function findConfiguration($buildPath)
    {
        foreach ($this->fileLocations as $possibleFile) {
            $possibleFilePath = rtrim($buildPath, '/') . '/' . $possibleFile;

            if ($this->filesystem->exists($possibleFilePath)) {
                return $possibleFilePath;
            }
        }

        return '';
    }
}
