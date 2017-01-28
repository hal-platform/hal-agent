<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Symfony;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\ProgressEvent;
use Symfony\Component\Console\Output\OutputInterface;

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
     * This needs to be the same guzzle client that is passed to HttpClientBuilder that
     * Github\Client uses....HTTP Plug isn't it great? -___-
     *
     * @var Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param OutputInterface $output
     *
     * @return null
     */
    public function enableDownloadProgress(OutputInterface $output)
    {
        $dispatcher = $this->client->getEmitter();
        $listener = $this->onUpdate($output);

        $dispatcher->on('progress', $listener);
        $dispatcher->on('complete', $this->onCompletion($output, $listener));
    }

    /**
     * @param OutputInterface $output
     * @param Closure $listener
     *
     * @return Closure
     */
    private function onCompletion(OutputInterface $output, Closure $listener)
    {
        return function (CompleteEvent $event) use ($output, $listener) {
            $event->getClient()->getEmitter()->removeListener('progress', $listener);
        };
    }

    /**
     * @param OutputInterface $output
     *
     * @return Closure
     */
    private function onUpdate(OutputInterface $output)
    {
        $prev = null;

        return function (ProgressEvent $event) use ($output, &$prev) {
            if (!$event->downloadSize) {
                return;
            }

            $percentage = round($event->downloaded / $event->downloadSize, 2) * 100;
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
