<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Testing;

use Hal\Agent\Build\PlatformInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PlatformHandlerStub implements PlatformInterface
{
    public $response;
    public $output;
    public $message;

    public function __invoke(array $commands, array $properties)
    {
        return $this->response;
    }

    /**
     * @param OutputInterface $output
     *
     * @return void
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * @return OutputInterface|null
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * @param string $message
     *
     * @return void
     */
    public function status($message)
    {
        $this->message = $message;
    }
}
