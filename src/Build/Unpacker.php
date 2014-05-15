<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\ProcessBuilder;

class Unpacker
{
    /**
     * @var string
     */
    const SUCCESS_UNPACK = 'Application code unpacked';
    const SUCCESS_LOCATED = 'Unpacked code located';
    const SUCCESS_SANITIZED = 'Unpacked code sanitized';

    const ERR_UNPACK_FAILURE = 'Unable to unpack code application code';
    const ERR_LOCATED = 'Unpacked code could not be located';
    const ERR_SANITIZED = 'Unpacked code could not be sanitized';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @param LoggerInterface $logger
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(LoggerInterface $logger, ProcessBuilder $processBuilder)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
    }

    /**
     * @param string $archive
     * @param string $buildPath
     * @return boolean
     */
    public function __invoke($archive, $buildPath)
    {
        $context = [
            'archive' => $archive,
            'buildPath' => $buildPath
        ];

        if (!$this->unpackArchive($buildPath, $archive, $context)) {
            return false;
        }

        if (!$unpackedPath = $this->locateUnpackedArchive($buildPath, $context)) {
            return false;
        }

        if (!$this->sanitizeUnpackedArchive($unpackedPath, $context)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     * @param array $context
     * @return string|null
     */
    private function locateUnpackedArchive($buildPath, array $context)
    {
        $cmd = ['find', $buildPath, '-type', 'd'];
        $process = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($cmd)
            ->getProcess();

        $process->setCommandLine($process->getCommandLine() . ' -name * -prune');

        $process->run();

        if ($process->isSuccessful()) {
            $this->logger->info(self::SUCCESS_LOCATED, $context);
            return strtok($process->getOutput(), "\n");
        }

        $context = array_merge($context, [
            'command' => $process->getCommandLine(),
            'exitCode' => $process->getExitCode(),
            'output' => $process->getOutput()
        ]);

        $this->logger->critical(self::ERR_LOCATED, $context);
        return null;
    }

    /**
     * @param string $unpackedPath
     * @param array $context
     * @return boolean
     */
    private function sanitizeUnpackedArchive($unpackedPath, array $context)
    {
        $process = $this->processBuilder
            ->setWorkingDirectory($unpackedPath)
            ->setArguments([''])
            ->getProcess()
            // processbuilder escapes input, but we need these wildcards to resolve correctly unescaped
            ->setCommandLine('mv * .[^.]* ..');

        $process->run();

        if ($process->isSuccessful()) {
            $this->logger->info(self::SUCCESS_SANITIZED, $context);

            // remove unpacked directory
            $cmd = ['rm', '-r', $unpackedPath];
            $removalProcess = $this->processBuilder
                ->setWorkingDirectory(null)
                ->setArguments($cmd)
                ->getProcess();

            $removalProcess->run();

            return true;
        }

        $context = array_merge($context, [
            'command' => $process->getCommandLine(),
            'exitCode' => $process->getExitCode(),
            'output' => $process->getOutput()
        ]);

        $this->logger->critical(self::ERR_SANITIZED, $context);
        return false;
    }

    /**
     * @param string $buildPath
     * @param string $archive
     * @param array $context
     * @return boolean
     */
    private function unpackArchive($buildPath, $archive, array $context)
    {
        $makeCommand = ['mkdir', $buildPath];
        $unpackCommand = ['tar', '-xzf', $archive, sprintf('--directory=%s', $buildPath)];

        $makeProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($makeCommand)
            ->getProcess();

        $unpackProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($unpackCommand)
            ->getProcess();

        // We do it like this so unpack will not be run if makeProcess is not succesful
        if ($makeProcess->run() === 0 && $unpackProcess->run() === 0) {
            $this->logger->info(self::SUCCESS_UNPACK, $context);
            return true;
        }

        $failedCommand = ($unpackProcess->isStarted()) ? $unpackProcess : $makeProcess;
        $context = array_merge($context, [
            'command' => $failedCommand->getCommandLine(),
            'exitCode' => $failedCommand->getExitCode(),
            'output' => $failedCommand->getOutput()
        ]);

        $this->logger->critical(self::ERR_UNPACK_FAILURE, $context);
        return false;
    }
}
