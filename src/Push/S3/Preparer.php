<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Push\Mover;
use Hal\Agent\Push\ReleasePacker;
use Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ProcessBuilder;

class Preparer
{
    use ProcessRunnerTrait;

    const ERR_DIST_NOT_VALID = 'Cannot find dist directory';

    const ERR_TIMEOUT = 'Validating files to upload took too long';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var Mover
     */
    private $mover;

    /**
     * @var ReleasePacker
     */
    private $packer;

    /**
     * @var string
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param Filesystem $filesystem
     * @param ProcessBuilder $processBuilder
     * @param Mover $mover
     * @param ReleasePacker $packer
     * @param string $commandTimeout
     */
    public function __construct(
        EventLogger $logger,
        Filesystem $filesystem,
        ProcessBuilder $processBuilder,
        Mover $mover,
        ReleasePacker $packer,
        $commandTimeout
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->processBuilder = $processBuilder;
        $this->mover = $mover;
        $this->packer = $packer;

        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $buildPath
     * @param string $distPath
     * @param string $tempArchive
     * @param string $destFile
     *
     * @return bool
     */
    public function __invoke($buildPath, $distPath, $tempArchive, $destFile)
    {
        $wholeSourcePath = rtrim($buildPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($distPath, DIRECTORY_SEPARATOR);

        // check if exists
        if (!$this->filesystem->exists($wholeSourcePath)) {
            $this->logger->event('failure', self::ERR_DIST_NOT_VALID, ['path' => $distPath]);
            return false;
        }

        // check if dir
        $isDirCommand = ['test', '-d', $wholeSourcePath];
        $process = $this->processBuilder
            ->setArguments($isDirCommand)
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        if (!$this->runProcess($process, $this->commandTimeout)) {
            return false;
        }

        $isDir = $process->isSuccessful();

        if ($isDir) {
            return $this->packer->packZipOrTar($buildPath, $distPath, $tempArchive, $destFile);

        } else {
            $mover = $this->mover;
            return $mover($wholeSourcePath, $tempArchive);
        }
    }
}
