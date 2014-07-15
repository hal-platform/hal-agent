<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\ProcessBuilder;

class Packer
{
    /**
     * @var string
     */
    const SUCCESS_PACKED = 'Build archived';
    const ERR_PACKED = 'Build archive did not pack correctly';

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

        $cmd = ['tar', '-czf', $targetFile, '.'];
        $process = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($cmd)
            ->getProcess();

        $process->run();

        if ($process->isSuccessful()) {
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
