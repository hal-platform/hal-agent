<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Utility;

use DateTime;
use Hal\Agent\Build\Unix\UnixBuildHandler;
use Hal\Agent\Build\Windows\WindowsBuildHandler;
use Hal\Core\JobGenerator;

trait ResolverTrait
{
    private static $TEMP_ARCHIVE_FILE = 'hal-%s-%s.tar.gz';
    private static $ARCHIVE_FILE = 'hal-%s.tar.gz';
    private static $UNIQUE_TEMP_PATH = 'hal-%s-%s';

    /**
     * @var string
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
        $type = ($type === 'release') ? 'release' : 'build';

        return $this->getLocalTempPath() . sprintf(static::$UNIQUE_TEMP_PATH, $type, $id);
    }

    /**
     * Generate a target for the build archive.
     *
     * Example:
     * var/archive/build-1234.tar.gz
     * var/archive/2015-05/build-1234.tar.gz
     *
     * @param string $id
     *
     * @return string
     */
    private function generateBuildArchiveFile($id)
    {
        $filename = sprintf(static::$ARCHIVE_FILE, $id);

        return sprintf(
            '%s/%s',
            $this->archivePath,
            $filename
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
        $type = ($type === 'release') ? 'release' : 'build';

        return $this->getLocalTempPath() . sprintf(static::$TEMP_ARCHIVE_FILE, $type, $id);
    }
}
