<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Generic;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Process\ProcessBuilder;

class FileCompression
{
    use ProcessRunnerTrait;

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Filesystem action';
    const ERR_TIMEOUT = 'Filesystem action timed out';

    const UNCOMPRESS_TGZ_FLAGS = '-vxz';
    const COMPRESS_TGZ_FLAGS = '-vcz';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var int
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param ProcessBuilder $processBuilder
     * @param int $commandTimeout
     */
    public function __construct(EventLogger $logger, ProcessBuilder $processBuilder, int $commandTimeout)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;

        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $workspacePath
     *
     * @return bool
     */
    public function createWorkspace(string $workspacePath): bool
    {
        $makeCommand = ['mkdir', $workspacePath];

        $process = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($makeCommand)
            ->getProcess();

        if (!$this->runProcess($process, $this->commandTimeout)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        return $this->processFailure(implode(' ', $makeCommand), $process);
    }

    /**
     * @param string $workspacePath
     * @param string $tarFile
     *
     * @return bool
     */
    public function unpackTarArchive(string $workspacePath, string $tarFile): bool
    {
        $unpackCommand = [
            'tar',
            static::UNCOMPRESS_TGZ_FLAGS,
            '--strip-components=1',
            sprintf('--file=%s', $tarFile),
            sprintf('--directory=%s', $workspacePath)
        ];

        $process = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($unpackCommand)
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        if (!$this->runProcess($process, $this->commandTimeout)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        return $this->processFailure(implode(' ', $unpackCommand), $process);
    }

    /**
     * @param string $workspacePath
     * @param string $tarFile
     *
     * @return bool
     */
    public function packTarArchive(string $workspacePath, string $tarFile): bool
    {
        $packCommand = [
            'tar',
            static::COMPRESS_TGZ_FLAGS,
            sprintf('--file=%s', $tarFile),
            '.'
        ];

        $process = $this->processBuilder
            ->setWorkingDirectory($workspacePath)
            ->setArguments($packCommand)
            ->setTimeout($this->commandTimeout)
            ->getProcess();


        if (!$this->runProcess($process, $this->commandTimeout)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        return $this->processFailure(implode(' ', $packCommand), $process);
    }
}
