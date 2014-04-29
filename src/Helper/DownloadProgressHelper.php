<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Helper;

use Guzzle\Common\Event;
use Guzzle\Http\Client;
use Symfony\Component\Console\Output\OutputInterface;

class DownloadProgressHelper
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
     * @return mixed
     */
    public function enableDownloadProgress(OutputInterface $output)
    {
        $listener = $this->progress($output);
        $this->client->getEventDispatcher()->addListener('curl.callback.progress', $this->progress($output));

        return $listener;
    }

    /**
     * @param mixed $listener
     * @return null
     */
    public function disableDownloadProgress($listener)
    {
        $this->client->getEventDispatcher()->removeListener('curl.callback.progress', $listener);
    }

    /**
     * @param OutputInterface $output
     * @return Closure
     */
    private function progress(OutputInterface $output)
    {
        $prev = null;
        return function (Event $event) use ($output, &$prev) {
            if (!$event['download_size']) {
                return;
            }

            $percentage = round($event['downloaded'] / $event['download_size'], 2) * 100;
            $message = sprintf('<info>Downloading:</info> %s%%', $percentage);
            if ($prev === $message) {
                return;
            }

            if ($prev !== null) {
                $message = str_pad($message, strlen($prev), "\x20", STR_PAD_RIGHT);
            }

            $prev = $message;
            $output->write("\x0D");
            $output->write($message);
        };
    }
}
