<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Remoting;

class CommandContext
{
    /**
     * @var string
     */
    private $username;
    private $server;

    /**
     * @var array|string
     */
    private $command;

    /**
     * @var string
     */
    private $sanitizedCommand;

    /**
     * @param string $username
     * @param string $server
     * @param string|array $command
     */
    public function __construct($username, $server, $command)
    {
        $this->username = $username;
        $this->server = $server;
        $this->command = $command;

        $this->sanitized = '';
    }

    /**
     * @return string
     */
    public function username()
    {
        return $this->username;
    }

    /**
     * @return string
     */
    public function server()
    {
        return $this->server;
    }

    /**
     * @return string
     */
    public function command()
    {
        $command = $this->command;
        if (is_array($command)) {
            $command = implode(' ', $command);
        }

        return $command;
    }

    /**
     * @return string
     */
    public function sanitized()
    {
        return $this->sanitized;
    }

    /**
     * @param string $sanitizedCommand
     *
     * @return self
     */
    public function withSanitized($sanitizedCommand)
    {
        $this->sanitized = $sanitizedCommand;
        return $this;
    }
}
