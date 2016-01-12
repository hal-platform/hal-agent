<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\Rsync;

use QL\Hal\Agent\Remoting\SSHProcess;

class ServerCommand
{
    const EVENT_MESSAGE = 'Run remote command';
    const EVENT_MESSAGE_CUSTOM = 'Run remote command "%s"';

    const SHORT_COMMAND_VALIDATION = '/^[\S\h]{1,25}$/';

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

            // Add build command to log message if short enough
            $msg = self::EVENT_MESSAGE;
            if (1 === preg_match(self::SHORT_COMMAND_VALIDATION, $command)) {
                $msg = sprintf(self::EVENT_MESSAGE_CUSTOM, $command);
            }

            if (!$response = $this->remoter->run($context, $env, [true, $msg])) {
                return false;
            }
        }

        // all good
        return true;
    }
}
