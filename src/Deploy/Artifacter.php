<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy;

use Hal\Agent\Job\FileCompression;
use Hal\Agent\Logger\EventLogger;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class Artifacter
{
    private const EVENT_MESSAGE = 'Download artifact from artifact repository';

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
     * @var string
     */
    private $artifactStoragePath;

    /**
     * @param EventLogger $logger
     * @param Filesystem $filesystem
     * @param FileCompression $fileCompression
     * @param string $artifactsPath
     */
    public function __construct(
        EventLogger $logger,
        Filesystem $filesystem,
        FileCompression $fileCompression,
        string $artifactsPath
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->fileCompression = $fileCompression;

        $this->artifactStoragePath = rtrim($artifactsPath, '/');
    }

    /**
     * @param string $deploymentPath
     * @param string $artifactFile
     * @param string $storedArtifact
     *
     * @return bool
     */
    public function __invoke(string $deploymentPath, string $artifactFile, string $storedArtifact): bool
    {
        $path = $this->artifactStoragePath;
        $fullArtifactPath = "${path}/${storedArtifact}";

        if (!$this->downloadArtifactFromStorage($fullArtifactPath, $artifactFile)) {
            return false;
        }

        if (!$this->unpackArtifactToWorkspace($artifactFile, $deploymentPath)) {
            return false;
        }

        $this->logger->event('success', static::EVENT_MESSAGE);
        return true;
    }

    /**
     * @param string $artifactFile
     * @param string $deploymentPath
     *
     * @return bool
     */
    private function unpackArtifactToWorkspace($artifactFile, $deploymentPath)
    {
        if (!$this->fileCompression->createWorkspace($deploymentPath)) {
            return false;
        }

        return $this->fileCompression->unpackTarArchive($deploymentPath, $artifactFile);
    }

    /**
     * @param string $permanentFile
     * @param string $artifactFile
     *
     * @return bool
     */
    private function downloadArtifactFromStorage($permanentFile, $artifactFile)
    {
        if (!$this->filesystem->exists($permanentFile)) {
            $this->logger->event('failure', static::EVENT_MESSAGE);
            return false;
        }

        try {
            $this->filesystem->copy($permanentFile, $artifactFile, true);
        } catch (IOException $e) {
            $this->logger->event('failure', static::EVENT_MESSAGE, [
                'error' => $e->getMessage()
            ]);

            return false;
        }

        return true;
    }
}
