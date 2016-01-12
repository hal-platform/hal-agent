<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Build\Windows;

use QL\Hal\Agent\Remoting\SSHProcess;

class Builder
{
    const EVENT_MESSAGE = 'Run Windows Build Command';

    /**
     * @var SSHProcess
     */
    private $remoter;

    /**
     * @param SSHProcess $remoter
     */
    public function __construct(SSHProcess $remoter)
    {
        $this->remoter = $remoter;
    }

    /**
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     * @param array $commands
     * @param array $env
     *
     * @return boolean
     */
    public function __invoke($remoteUser, $remoteServer, $remotePath, array $commands, array $env)
    {
        $chdir = sprintf('cd "%s" &&', $remotePath);

        foreach ($commands as $command) {
            $context = $this->remoter
                ->createCommand($remoteUser, $remoteServer, [$chdir, $command])
                ->withSanitized($command);

            if (!$response = $this->remoter->run($context, $env, [true, self::EVENT_MESSAGE])) {
                return false;
            }
        }

        // all good
        return true;
    }
}
