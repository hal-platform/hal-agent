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
     * @return boolean
     */
    public function __invoke($archive, $buildPath)
    {
        if (!$this->unpackArchive($buildPath, $archive)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     * @param string $archive
     * @return boolean
     */
    private function unpackArchive($buildPath, $archive)
    {
        $makeCommand = ['mkdir', $buildPath];
        $unpackCommand = [
            'tar',
            '-vxz',
            '--strip-components=1',
            sprintf('--file=%s', $archive),
            sprintf('--directory=%s', $buildPath)
        ];

        $makeProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($makeCommand)
            ->getProcess();

        $unpackProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($unpackCommand)
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        // We do it like this so unpack will not be run if makeProcess is not succesful
        if ($makeProcess->run() === 0) {
            if (!$this->runProcess($unpackProcess, $this->commandTimeout)) {
                return false;
            }

            if ($unpackProcess->isSuccessful()) {
                return true;
            }
        }

        $failedCommand = ($unpackProcess->isStarted()) ? $unpackProcess : $makeProcess;

        $dispCommand = [
            implode(' ', $makeCommand),
            implode(' ', $unpackCommand)
        ];

        return $this->processFailure($dispCommand, $failedCommand);
    }
}
