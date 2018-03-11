<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3;

use SplFileInfo;

class Comparator
{
    /**
     * @param SplFileInfo $localFile
     * @param array $s3object
     *
     * @return bool
     */
    public function areSame(SplFileInfo $localFile = null, array $s3object): bool
    {
        if (is_null($localFile)) {
            return false;
        }

        if (!$this->areSameSize($localFile, $s3object)) {
            return false;
        }

        if (!$this->wereLastModifiedSameDate($localFile, $s3object)) {
            return false;
        }

        return true;
    }

    /**
     * @param SplFileInfo $localFile
     * @param array $s3object
     *
     * @return bool
     */
    protected function areSameSize(SplFileInfo $localFile, array $s3object)
    {
        if (!isset($s3object['Size'])) {
            return false;
        }

        return $localFile->getSize() === $s3object['Size'];
    }

    /**
     * @param SplFileInfo $localFile
     * @param array $s3object
     *
     * @return bool
     */
    protected function wereLastModifiedSameDate(SplFileInfo $localFile, array $s3object)
    {
        if (!isset($s3object['LastModified'])) {
            return false;
        }

        $localTime = $localFile->getMTime();
        $remoteTime = $s3object['LastModified']->getTimestamp();

        return $localTime === $remoteTime;
    }
}
