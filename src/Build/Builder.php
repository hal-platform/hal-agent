<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class Builder
{
    /**
     * @var string
     */
    const SUCCESS_BUILDING = 'Build command executed';
    const ERR_BUILDING = 'Build command executed with errors';

    /**
     * @var string
     */
    const CMD_BUILD = '%s 2>&1';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $buildPath
     * @param string $command
     * @param array $env
     * @return boolean
     */
    public function __invoke($buildPath, $command, array $env)
    {
        $context = [
            'buildPath' => $buildPath,
            'buildCommand' => $command
        ];

        $process = new Process(
            sprintf(self::CMD_BUILD, $command),
            $buildPath,
            $env,
            null,
            600
        );
        $process->run();

        // we always want the output
        $context = array_merge($context, ['output' => $process->getOutput()]);

        if ($process->isSuccessful()) {
            $this->logger->info(self::SUCCESS_BUILDING, $context);
            return true;
        }

        $context = array_merge($context, ['exitCode' => $process->getExitCode()]);
        $this->logger->critical(self::ERR_BUILDING, $context);
        return false;
    }
}
