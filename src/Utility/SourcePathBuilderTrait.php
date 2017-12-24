<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Utility;

use Hal\Agent\Push\PushException;

trait SourcePathBuilderTrait
{
    /**
     * @param string $buildPath
     * @param string $distPath
     *
     * @return string $sourcePath
     */
    private function getWholeSourcePath($buildPath, $distPath)
    {
        return rtrim($buildPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($distPath, DIRECTORY_SEPARATOR);
    }
}
