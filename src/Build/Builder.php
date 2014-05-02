<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;

class Builder
{
    /**
     * @var string
     */
    const SUCCESS_BUILDING = 'Build command successfully run';
    const ERR_BUILDING = 'Build command executed with errors';

    /**
     * @var string
     */
    const CMD_BUILD = 'cd %s && %s 2>&1';

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

        exec('env', $out, $code);
        $context['environmentVariables'] = $out;

        $command = sprintf(self::CMD_BUILD, $buildPath, $command);
        $command = 'env';
        // $commandWithVars = $this->prependEnvironment($command, $env);
        // $context['actualBuildCommand'] = $commandWithVars;

        exec($command, $output, $code);

        // we always want the output
        $context = array_merge($context, ['output' => $output]);

        if ($code === 0) {
            $this->logger->info(self::SUCCESS_BUILDING, $context);
            return true;
        }

        $context = array_merge($context, ['buildExitCode' => $code]);
        $this->logger->critical(self::ERR_BUILDING, $context);
        return false;
    }

    /**
     * @param string $command
     * @param array $env
     * @return string
     */
    private function prependEnvironment($command, array $env)
    {
        $cmdEnvs = '';
        foreach ($env as $name => $property) {
            $cmdEnvs .= escapeshellarg($name) . '=' . escapeshellarg($property) . '; ';
        }

        return $cmdEnvs . $command;
    }
}
