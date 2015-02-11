<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Utility;

use QL\Hal\Agent\Build\Unix\UnixBuildHandler;
use QL\Hal\Agent\Build\Windows\WindowsBuildHandler;
use QL\Hal\Core\Entity\Build;
use Symfony\Component\Process\ProcessBuilder;

trait ResolverTrait
{
    private static $TEMP_ARCHIVE_FILE = 'hal9000-%s-%s.tar.gz';
    private static $ARCHIVE_FILE = 'hal9000-%s.tar.gz';

    private static $UNIQUE_TEMP_PATH = 'hal9000-%s-%s';

    /**
     * @type string
     */
    private $localTempPath;
    private $archivePath;

    /**
     * @param string $path
     * @return void
     */
    public function setLocalTempPath($path)
    {
        $this->localTempPath = rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * @param string $path
     * @return void
     */
    public function setArchivePath($path)
    {
        $this->archivePath = rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Get temp path
     *
     * Example:
     * var/temp/builds/
     *
     * @return string
     */
    private function getLocalTempPath()
    {
        if (!$this->localTempPath) {
            $this->localTempPath = rtrim($this->localTempPath, sys_get_temp_dir());
        }

        return sprintf('%s/', $this->localTempPath);
    }

    /**
     * Generate a unique temporary scratch space path for performing file system actions.
     *
     * Example:
     * var/temp/builds/hal9000-build-1234
     *
     * @param string $id
     * @param string $id
     *
     * @return string
     */
    private function generateLocalTempPath($id, $type = 'build')
    {
        $type = ($type === 'push') ? 'push' : 'build';

        return $this->getLocalTempPath() . sprintf(static::$UNIQUE_TEMP_PATH, $type, $id);
    }

    /**
     * Generate a target for the build archive.
     *
     * Example:
     * var/archive/build-1234.tar.gz
     *
     * @param string $id
     *
     * @return string
     */
    private function generateBuildArchiveFile($id)
    {
        return sprintf(
            '%s/%s',
            $this->archivePath,
            sprintf(static::$ARCHIVE_FILE, $id)
        );
    }

    /**
     * Generate a local temporary target for the build archive.
     *
     * This is so we can transfer the entire archive to/from the remote archive as a single file, and perform actual unpack/pack functions locally.
     *
     * Example:
     * var/temp/builds/build-1234.tar.gz
     *
     * @param string $id
     *
     * @return string
     */
    private function generateTempBuildArchiveFile($id, $type = 'build')
    {
        $type = ($type === 'push') ? 'push' : 'build';

        return $this->getLocalTempPath() . sprintf(static::$TEMP_ARCHIVE_FILE, $type, $id);
    }
}
