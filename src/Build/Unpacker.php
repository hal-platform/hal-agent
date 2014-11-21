<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\ProcessRunnerTrait;
use Symfony\Component\Process\ProcessBuilder;

class Unpacker
{
    use ProcessRunnerTrait;

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Unpack GitHub archive';

    const ERR_UNPACK_FAILURE = 'Unable to unpack GitHub archive';
    const ERR_UNPACKING_TIMEOUT = 'Unpacking GitHub archive took too long';
    const ERR_LOCATED = 'Unpacked GitHub archive could not be located';
    const ERR_SANITIZED = 'Unpacked GitHub archive directory was not empty after sanitizing.';

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

        if (!$unpackedPath = $this->locateUnpackedArchive($buildPath)) {
            return false;
        }

        if (!$this->sanitizeUnpackedArchive($unpackedPath)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     * @return string|null
     */
    private function locateUnpackedArchive($buildPath)
    {
        $cmd = ['find', $buildPath, '-type', 'd'];
        $process = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($cmd)
            ->getProcess();

        $process->setCommandLine($process->getCommandLine() . ' -name * -prune');

        $process->run();

        if ($process->isSuccessful()) {
            return strtok($process->getOutput(), "\n");
        }

        $this->logger->event('failure', self::ERR_LOCATED, [
            'command' => $process->getCommandLine(),
            'exitCode' => $process->getExitCode(),
            'output' => $process->getOutput(),
            'errorOutput' => $process->getErrorOutput()
        ]);

        return null;
    }

    /**
     * @param string $unpackedPath
     * @return boolean
     */
    private function sanitizeUnpackedArchive($unpackedPath)
    {
        $process = $this->processBuilder
            ->setWorkingDirectory($unpackedPath)
            ->setArguments([''])
            ->getProcess()
            // processbuilder escapes input, but we need these wildcards to resolve correctly unescaped
            ->setCommandLine('mv {,.[!.],..?}* ..');

        $process->run();

        // remove unpacked directory
        $cmd = ['rmdir', $unpackedPath];
        $removalProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($cmd)
            ->getProcess();

        $removalProcess->run();

        if ($removalProcess->isSuccessful()) {
            return true;
        }

        $this->logger->event('failure', self::ERR_SANITIZED, [
            'command' => $removalProcess->getCommandLine(),
            'exitCode' => $removalProcess->getExitCode(),
            'output' => $removalProcess->getOutput(),
            'errorOutput' => $removalProcess->getErrorOutput()
        ]);

        return false;
    }

    /**
     * @param string $buildPath
     * @param string $archive
     * @return boolean
     */
    private function unpackArchive($buildPath, $archive)
    {
        $makeCommand = ['mkdir', $buildPath];
        $unpackCommand = ['tar', '-vxzf', $archive, sprintf('--directory=%s', $buildPath)];

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
            if (!$this->runProcess($unpackProcess, $this->logger, self::ERR_UNPACKING_TIMEOUT, $this->commandTimeout)) {
                return false;
            }

            if ($unpackProcess->isSuccessful()) {
                return true;
            }
        }

        $failedCommand = ($unpackProcess->isStarted()) ? $unpackProcess : $makeProcess;

        $this->logger->event('failure', self::ERR_UNPACK_FAILURE, [
            'command' => $failedCommand->getCommandLine(),
            'exitCode' => $failedCommand->getExitCode(),
            'output' => $failedCommand->getOutput(),
            'errorOutput' => $failedCommand->getErrorOutput()
        ]);

        return false;
    }
}
