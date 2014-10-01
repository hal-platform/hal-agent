<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Psr\Log\LoggerInterface;
use QL\Hal\Agent\ProcessRunnerTrait;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\ProcessUtils;

class ServerCommand
{
    use ProcessRunnerTrait;

    /**
     * @var string
     */
    const SUCCESS_COMMAND = 'Server command executed';
    const ERR_COMMAND = 'Server command executed with errors';
    const ERR_COMMAND_TIMEOUT = 'Server command took too long';

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
    private $sshUser;

    /**
     * @var int
     */
    private $commandTimeout;

    /**
     * @param LoggerInterface $logger
     * @param ProcessBuilder $processBuilder
     * @param string $sshUser
     * @param int $commandTimeout
     */
    public function __construct(LoggerInterface $logger, ProcessBuilder $processBuilder, $sshUser, $commandTimeout)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->sshUser = $sshUser;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $hostname
     * @param string $remotePath
     * @param string $serverCommand
     * @param array $env
     * @return boolean
     */
    public function __invoke($hostname, $remotePath, $serverCommand, array $env)
    {
        $context = [
            'hostname' => $hostname,
            'remotePath' => $remotePath,
            'serverCommand' => $serverCommand,
            'environment' => $env
        ];

        $serverCommand = $this->sanitizeCommand($serverCommand);

        // Add environment variables if possible
        if ($envSetters = $this->formatEnvSetters($env)) {
            $serverCommand = implode(' ', [$envSetters, $serverCommand]);
        }

        // move to the application directory before command is executed
        $remoteCommand = implode(' && ', [
            sprintf('cd %s', $remotePath),
            $serverCommand
        ]);

        $command = implode(' ', [
            'ssh',
            sprintf('%s@%s', $this->sshUser, $hostname),
            sprintf('"%s"', $remoteCommand)
        ]);

        $context = array_merge($context, ['fullCommand' => $command]);

        $process = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments([''])
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        $process->setCommandLine($command);

        if (!$this->runProcess($process, $this->logger, self::ERR_COMMAND_TIMEOUT, $this->commandTimeout)) {
            return false;
        }

        // we always want the output
        $context = array_merge($context, ['output' => $process->getOutput()]);

        if ($process->isSuccessful()) {
            $this->logger->info(self::SUCCESS_COMMAND, $context);
            return true;
        }

        $context = array_merge($context, ['exitCode' => $process->getExitCode()]);
        $this->logger->critical(self::ERR_COMMAND, $context);
        return false;
    }

    /**
     * @var array $env
     * @return string
     */
    private function formatEnvSetters(array $env)
    {
        $envSetters = [];
        foreach ($env as $property => $value) {
            $envSetters[] = sprintf('%s=%s', $property, ProcessUtils::escapeArgument($value));
        }

        return implode(' ', $envSetters);
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

        // manually escape user supplied command
        $parameters = array_map(['Symfony\Component\Process\ProcessUtils', 'escapeArgument'], $parameters);

        // Combine user command back into string
        return implode(' ', $parameters);
    }
}
