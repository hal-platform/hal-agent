<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Hal\Agent\Job\FileCompression;
use Hal\Agent\Logger\EventLogger;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class Compressor
{
    private const ERR_SOURCE_NOT_VALID = 'Invalid source file or directory specified';
    private const ERR_PREPARE_FILE_FOR_UPLOAD = 'Failed to prepare artifact for upload';
    private const ERR_INVALID_EXTENSION = 'Artifact file extension is not valid';

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
     * @param EventLogger $logger
     * @param Filesystem $filesystem
     * @param FileCompression $fileCompression
     */
    public function __construct(EventLogger $logger, Filesystem $filesystem, FileCompression $fileCompression)
    {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->fileCompression = $fileCompression;
    }

    /**
     * @param string $sourcePath
     * @param string $targetFile
     * @param string $remoteFile
     *
     * @return bool
     */
    public function __invoke(string $sourcePath, string $targetFile, string $remoteFile): bool
    {
        // Do not allow dir traversal. Source path must be within workspace
        if (stripos($sourcePath, '/..') !== false) {
            $this->logger->event('failure', self::ERR_SOURCE_NOT_VALID, ['path' => $sourcePath]);
            return false;
        }

        if (!$this->filesystem->exists($sourcePath)) {
            $this->logger->event('failure', static::ERR_SOURCE_NOT_VALID);
            return false;
        }

        if (is_file($sourcePath)) {
            return $this->moveArtifact($sourcePath, $targetFile);
        }

        if (!is_dir($sourcePath)) {
            $this->logger->event('failure', static::ERR_SOURCE_NOT_VALID, ['path' => $sourcePath]);
            return false;
        }

        $supported = [
            '.zip' => 'zip',
            '.tgz' => 'tar',
            '.tar.gz' => 'tar'
        ];

        $archiver = null;
        foreach ($supported as $extension => $method) {
            if (1 === preg_match('/' .  preg_quote($extension) . '$/', $remoteFile)) {
                $archiver = $this->getArchiver($method);
                break;
            }
        }

        if (!$archiver) {
            $this->logger->event('failure', static::ERR_INVALID_EXTENSION, [
                'validExtensions' => array_keys($supported)
            ]);
            return false;
        }

        return $archiver($sourcePath, $targetFile);
    }

    /**
     * @param string $type
     *
     * @return callable|null
     */
    private function getArchiver($type)
    {
        if ($type === 'tar') {
            return function ($source, $target) {
                return $this->fileCompression->packTarArchive($source, $target);
            };
        }

        if ($type === 'zip') {
            return function ($source, $target) {
                return $this->fileCompression->packZipArchive($source, $target);
            };
        }

        return null;
    }

    /**
     * @param string $storedFile
     * @param string $exportFile
     *
     * @return bool
     */
    private function moveArtifact($storedFile, $exportFile)
    {
        try {
            $this->filesystem->copy($storedFile, $exportFile, true);
        } catch (IOException $e) {
            $this->logger->event('failure', static::ERR_PREPARE_FILE_FOR_UPLOAD, [
                'error' => $e->getMessage()
            ]);

            return false;
        }

        return true;
    }
}
