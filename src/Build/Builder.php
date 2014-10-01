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

class Builder
{
    use ProcessRunnerTrait;

    /**
     * @var string
     */
    const SUCCESS_BUILDING = 'Build command executed';
    const ERR_BUILDING = 'Build command executed with errors';
    const ERR_BUILDING_TIMEOUT = 'Build command took too long';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PackageManagerPreparer
     */
    private $preparer;

    /**
     * Time (in seconds) to wait for the build to process before aborting
     *
     * @var int
     */
    private $commandTimeout;

    /**
     * @param LoggerInterface $logger
     * @param ProcessBuilder $processBuilder
     * @param PackageManagerPreparer $preparer
     * @param int $commandTimeout
     */
    public function __construct(LoggerInterface $logger, ProcessBuilder $processBuilder, PackageManagerPreparer $preparer, $commandTimeout)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->preparer = $preparer;
        $this->commandTimeout = $commandTimeout;
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

        $command = $this->sanitizeCommand($command);

        $process = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($command)
            ->addEnvironmentVariables($env)
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        // prepare package manager configuration
        call_user_func($this->preparer, $env);

        if (!$this->runProcess($process, $this->logger, self::ERR_BUILDING_TIMEOUT, $this->commandTimeout)) {
            return false;
        }

        // we always want the output
        $context = array_merge($context, ['output' => $process->getOutput()]);

        if ($process->isSuccessful()) {
            $this->logger->info(self::SUCCESS_BUILDING, $context);
            return true;
        }

        $errorContext = ['exitCode' => $process->getExitCode(), 'errorOutput' => $process->getErrorOutput()];
        $context = array_merge($context, $errorContext);

        $this->logger->critical(self::ERR_BUILDING, $context);
        return false;
    }

    /**
     * @var string $command
     * @return string
     */
    private function sanitizeCommand($command)
    {
        // parameterize the command
        $parameters = explode(' ', $command);

        // remove empty parameters
        $parameters = array_filter($parameters, function($v) {
            return (trim($v) !== '');
        });

        // collapse array elements
        return array_values($parameters);
    }
}
