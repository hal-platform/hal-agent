<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux\Steps;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class Exporter
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
     * @param string $workspacePath
     * @param string $stagePath
     *
     * @return bool
     */
    public function __invoke(string $workspacePath, string $stagePath): bool
    {
        $this->removeLocalFiles($stagePath);

        $from = $workspacePath;
        $to = $stagePath;

        return $this->copyFiles($from, $to);
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    private function removeLocalFiles($path)
    {
        try {
            if ($this->filesystem->exists($path)) {
                $this->filesystem->remove($path);
            }

        } catch (IOException $e) {
            return false;
        }

        return true;
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return bool
     */
    private function copyFiles($from, $to)
    {
        try {
            $this->filesystem->copy($from, $to);

        } catch (IOException $e) {
            return false;
        }

        return true;
    }
}
