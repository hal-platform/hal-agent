<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;
use QL\Hal\Agent\ProcessRunnerTrait;
use Symfony\Component\Process\ProcessBuilder;

class Packer
{
    use ProcessRunnerTrait;

    /**
     * @var string
     */
    const SUCCESS_PACKED = 'Build archived';
    const ERR_PACKED = 'Build archive did not pack correctly';
    const ERR_PACKING_TIMEOUT = 'Archiving the build took too long';

    /**
     * @var LoggerInterface
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
     * @param LoggerInterface $logger
     * @param ProcessBuilder $processBuilder
     * @param string $commandTimeout
     */
    public function __construct(LoggerInterface $logger, ProcessBuilder $processBuilder, $commandTimeout)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $buildPath
     * @param string $targetFile
     * @return boolean
     */
    public function __invoke($buildPath, $targetFile)
    {
        $context = [
            'buildPath' => $buildPath,
            'archive' => $targetFile
        ];

        $cmd = ['tar', '-vczf', $targetFile, '.'];
        $process = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($cmd)
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        if (!$this->runProcess($process, $this->logger, self::ERR_PACKING_TIMEOUT, $this->commandTimeout)) {
            return false;
        }

        if ($process->isSuccessful()) {

            $size = filesize($targetFile) / 1048576;
            $context['archiveSize'] = sprintf('%s MB', round($size, 2));

            $this->logger->info(self::SUCCESS_PACKED, $context);
            return true;
        }

        $context = array_merge($context, [
            'command' => $process->getCommandLine(),
            'exitCode' => $process->getExitCode(),
            'output' => $process->getOutput()
        ]);
        $this->logger->critical(self::ERR_PACKED, $context);
        return false;
    }
}
