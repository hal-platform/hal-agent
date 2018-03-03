<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Hal\Agent\Job\FileCompression;

class Compressor
{
    /**
     * @var FileCompression
     */
    private $fileCompression;

    /**
     * @param FileCompression $fileCompression
     */
    public function __construct(FileCompression $fileCompression)
    {
        $this->fileCompression = $fileCompression;
    }

    public function __invoke(string $sourcePath, string $targetPath)
    {
        // Hal 2.0 supports both Zip and Tar, but fileCompression currently only supports Tar
        // Are we forcing Tar compression, or should we add zip support for to FileCompression?
        return $this->fileCompression->packTarArchive($sourcePath, $targetPath);
    }
}
