<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Generic;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;

class LocalCleaner
{
    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @param array $artifacts
     *
     * @return bool
     */
    public function __invoke(array $artifacts)
    {
        $isSuccess = true;

        foreach ($artifacts as $artifact) {
            if (!$this->filesystem->exists($artifact)) {
                continue;
            }

            try {
                $this->filesystem->remove($artifact);

            } catch (IOException $e) {
                $isSuccess = false;
            }
        }

        return $isSuccess;
    }
}
