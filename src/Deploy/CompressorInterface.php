<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy;

/**
 * A module that understands how to do a compression
 */
interface CompressorInterface
{
    /**
     * @param string $sourcePath
     * @param string $targetFile
     * @param string $remoteFile
     *
     * @return bool
     */
    public function __invoke(string $sourcePath, string $targetFile, string $remoteFile): bool;
}
