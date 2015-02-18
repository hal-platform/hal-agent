<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Symfony;

use Closure;
use Guzzle\Common\Event;
use Guzzle\Http\Client;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Output progressive status of a file download to the symfony console output.
 */
class GuzzleDownloadProgress
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param OutputInterface $output
     * @return null
     */
    public function enableDownloadProgress(OutputInterface $output)
    {
        $dispatcher = $this->client->getEventDispatcher();
        $listener = $this->onUpdate($output);

        $dispatcher->addListener('curl.callback.progress', $listener);
        $dispatcher->addListener('request.complete', $this->onCompletion($output, $listener));
    }

    /**
     * @param OutputInterface $output
     * @param Closure $listener
     * @return Closure
     */
    private function onCompletion(OutputInterface $output, Closure $listener)
    {
        return function (Event $event, $name, EventDispatcherInterface $dispatcher) use ($output, $listener) {
            $dispatcher->removeListener('curl.callback.progress', $listener);
        };
    }

    /**
     * @param OutputInterface $output
     * @return Closure
     */
    private function onUpdate(OutputInterface $output)
    {
        $prev = null;
        return function (Event $event) use ($output, &$prev) {
            if (!$event['download_size']) {
                return;
            }

            $percentage = round($event['downloaded'] / $event['download_size'], 2) * 100;
            $message = sprintf('<info>Percentage complete:</info> %s%%', $percentage);
            if ($prev === $message) {
                return;
            }

            if ($prev !== null) {
                $message = str_pad($message, strlen($prev), ' ', STR_PAD_RIGHT);
            }

            $prev = $message;
            $output->write("\x0D");

            if ($percentage == '100') {
                $output->writeln($message);
            } else {
                $output->write($message);
            }
        };
    }
}
