<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\SwiftMailer;

use Swift_Message;

class Message extends Swift_Message
{
    private $repo       = '';
    private $env        = '';
    private $server     = '';
    private $pusher     = '';
    private $status     = '';

    /**
     *  {@inheritdoc}
     */
    public function __construct($subject, $body = null, $contentType = null, $charset = null)
    {
        parent::__construct($subject, null, 'text/html', $charset);
    }

    /**
     *  Add build details
     *
     *  @param string $email
     *  @param string $repo
     *  @param string $env
     *  @param string $server
     *  @param string $pusher
     */
    public function setBuildDetails($email, $repo, $env, $server, $pusher)
    {
        $this->repo = $repo;
        $this->env = $env;
        $this->server = $server;
        $this->pusher = $pusher;

        $this->setTo($email);
    }

    /**
     *  Add build result
     *
     *  @param string|bool $status
     */
    public function setBuildResult($status)
    {
        $this->status = $status;

        $this->setSubject($this->prepareSubject());
    }

    /**
     *  Get the formatted subject string
     *
     *  @return mixed|string
     */
    private function prepareSubject()
    {
        $replacements = array(
            '{repo}'    => $this->repo,
            '{env}'     => $this->env,
            '{server}'  => $this->server,
            '{pusher}'  => $this->pusher,
            '{status}'  => ($this->status) ? 'SUCCESS' : 'FAILURE'
        );

        $subject = parent::getSubject();

        foreach ($replacements as $key => $value) {
            $subject = str_replace($key, $value, $subject);
        }

        return $subject;
    }
}
