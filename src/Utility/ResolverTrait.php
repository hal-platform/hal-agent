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
use QL\Hal\Core\JobIdGenerator;

trait ResolverTrait
{
    private static $TEMP_ARCHIVE_FILE = 'hal9000-%s-%s.tar.gz';
    private static $ARCHIVE_FILE = 'hal9000-%s.tar.gz';

    private static $UNIQUE_TEMP_PATH = 'hal9000-%s-%s';

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
        $type = ($type === 'push') ? 'push' : 'build';

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

        if ($dateOfJob = $this->parseDateFromJobId($id)) {
            $filename = sprintf('%s/%s', $dateOfJob->format('Y-m'), $filename);
        }

        return sprintf(
            '%s/%s',
            $this->archivePath,
            $filename
        );
    }

    /**
     * Generate a target for the build archive.
     *
     * @deprecated
     *
     * Example:
     * var/archive/hal9000/build-1234.tar.gz
     *
     * @param string $id
     *
     * @return string
     */
    private function generateLegacyBuildArchiveFile($id)
    {
        return sprintf(
            '%s/hal9000/%s',
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

    /**
     * @param string $id
     *
     * @return DateTime|null
     */
    private function parseDateFromJobId($id)
    {
        if (1 !== preg_match('/^(b|p)[\d]{1}\.([a-zA-Z0-9]{3})/', $id, $matches)) {
            return null;
        }

        $base58 = str_split(array_pop($matches));
        if (count($base58) !== 3) {
            return null;
        }

        // parse base58 to base10
        $base10 = 0;
        array_walk($base58, function($v, $k) use (&$base10) {
            $base = strpos(JobIdGenerator::BASE58, $v);

            if ($base === false) $base = 0;
            $base10 += ($base * pow(58, 2 - $k));
        });

        $base10 = (string) $base10;
        if (strlen($base10) !== 5) {
            return null;
        }

        $parsed = sprintf('20%d %d', substr($base10, 0, 2), substr($base10, 2));

        return DateTime::createFromFormat('Y z', $parsed);
    }
}
