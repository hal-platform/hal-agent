<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\ProcessBuilder;

class Builder
{
    /**
     * @var string
     */
    const SUCCESS_BUILDING = 'Build command executed';
    const ERR_BUILDING = 'Build command executed with errors';

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
     * @param PackageManagerPreparer $preparer
     */
    public function __construct(LoggerInterface $logger, ProcessBuilder $processBuilder, PackageManagerPreparer $preparer)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->preparer = $preparer;
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
            ->setTimeout(300)
            ->getProcess();

        // prepare package manager configuration
        call_user_func($this->preparer, $env);

        $process->run();

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
