<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Process\ProcessBuilder;

/**
 *
 * TODO REMOVE
 * TODO REMOVE
 * TODO REMOVE    - This was combined into FileCompression and Downloader
 * TODO REMOVE
 * TODO REMOVE
 *
 */
class Unpacker
{
    use ProcessRunnerTrait;

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Unpack GitHub archive';
    const ERR_TIMEOUT = 'Unpacking GitHub archive took too long';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var string
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param ProcessBuilder $processBuilder
     * @param string $commandTimeout
     */
    public function __construct(EventLogger $logger, ProcessBuilder $processBuilder, $commandTimeout)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $archive
     * @param string $buildPath
     *
     * @return bool
     */
    public function __invoke($archive, $buildPath): bool
    {
        if (!$this->createWorkspace($buildPath)) {
            return false;
        }

        if (!$this->unpackArchive($buildPath, $archive)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     *
     * @return bool
     */
    private function createWorkspace($buildPath)
    {
        $makeCommand = ['mkdir', $buildPath];

        $makeProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($makeCommand)
            ->getProcess();

        if (!$this->runProcess($makeProcess, $this->commandTimeout)) {
            return false;
        }

        if ($makeProcess->isSuccessful()) {
            return true;
        }

        return $this->processFailure(implode(' ', $makeCommand), $makeProcess);
    }

    /**
     * @param string $buildPath
     * @param string $archive
     *
     * @return bool
     */
    private function unpackArchive($buildPath, $archive)
    {
        $unpackCommand = [
            'tar',
            '-vxz',
            '--strip-components=1',
            sprintf('--file=%s', $archive),
            sprintf('--directory=%s', $buildPath)
        ];

        $unpackProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($unpackCommand)
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        if (!$this->runProcess($unpackProcess, $this->commandTimeout)) {
            return false;
        }

        if ($unpackProcess->isSuccessful()) {
            return true;
        }

        return $this->processFailure(implode(' ', $unpackCommand), $unpackProcess);
    }
}
