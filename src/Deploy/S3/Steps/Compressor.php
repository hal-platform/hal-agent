<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Hal\Agent\Job\FileCompression;

use Hal\Agent\Deploy\DeployException;

class Compressor
{
    const ERR_INVALID_EXTENSION = "Target file's extension is not valid: valid extensions are .zip, .tgz, and .tar.gz";

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

    /**
     * @param string $sourcePath
     * @param string $targetPath
     * @param string $destinationFileName
     *
     * @return bool
     * @throws DeployException
     */
    public function __invoke(string $sourcePath, string $targetPath, string $destinationFileName)
    {
        $zipPacker = function ($source, $target) {
            return $this->fileCompression->packZipArchive($source, $target);
        };
        $tarPacker = function ($source, $target) {
            return $this->fileCompression->packTarArchive($source, $target);
        };

        $supported = [
            '.zip' => $zipPacker,
            '.tgz' => $tarPacker,
            '.tar.gz' => $tarPacker
        ];

        foreach ($supported as $extension => $method) {
            if (1 === preg_match('/' .  preg_quote($extension) . '$/', $destinationFileName)) {
                $archiver = $method;
                break;
            }
        }

        if (!isset($archiver)) {
            throw new DeployException(self::ERR_INVALID_EXTENSION);
        }

        return $archiver($sourcePath, $targetPath);
    }
}
