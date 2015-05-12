<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Remoting;

class CommandContext
{
    /**
     * @type string
     */
    private $username;
    private $server;

    /**
     * @type array|string
     */
    private $command;

    /**
     * @type string
     */
    private $sanitizedCommand;

    /**
     * @type bool
     */
    private $interactive;

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
        $this->interactive = false;
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
     * @return bool
     */
    public function isInteractive()
    {
        return $this->interactive;
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
     * @return self
     */
    public function withSanitized($sanitizedCommand)
    {
        $this->sanitized = $sanitizedCommand;
        return $this;
    }

    /**
     * @param bool $interactive
     * @return self
     */
    public function withIsInteractive($interactive)
    {
        $this->interactive = $interactive;
        return $this;
    }
}
