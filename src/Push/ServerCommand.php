<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\ProcessBuilder;

class ServerCommand
{
    /**
     * @var string
     */
    const SUCCESS_COMMAND = 'Post push command executed';
    const ERR_COMMAND = 'Post push command executed with errors';

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
     * @param LoggerInterface $logger
     * @param ProcessBuilder $processBuilder
     * @param string $sshUser
     */
    public function __construct(LoggerInterface $logger, ProcessBuilder $processBuilder, $sshUser)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->sshUser = $sshUser;
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
        $actualRemoteCommand = $serverCommand;

        $context = [
            'hostname' => $hostname,
            'remotePath' => $remotePath,
            'serverCommand' => $serverCommand,
            'environment' => $env
        ];

        if ($envSetters = $this->formatEnvSetters($env)) {
            $serverCommand = implode(' ', [$envSetters, $serverCommand]);
        }

        $remoteCommand = implode(' && ', [
            sprintf('cd %s', $remotePath),
            $serverCommand . ' 2>&1'
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
            ->setTimeout(300)
            ->getProcess();

        $process->setCommandLine($command);
        $process->run();

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
            $envSetters[] = sprintf('%s=%s', $property, escapeshellarg($value));
        }

        return implode(' ', $envSetters);
    }
}
