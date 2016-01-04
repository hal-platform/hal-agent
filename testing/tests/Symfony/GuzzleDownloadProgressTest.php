<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Symfony;

use Guzzle\Common\Event;
use Guzzle\Http\Client;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class GuzzleDownloadProgressTest extends PHPUnit_Framework_TestCase
{
    public $client;

    public function testHelperAttachesListeners()
    {
        $output = new BufferedOutput;
        $client = new Client;
        $helper = new GuzzleDownloadProgress($client);

        $helper->enableDownloadProgress($output);

        $dispatcher = $client->getEventDispatcher();
        $progressListeners = $dispatcher->getListeners('curl.callback.progress');
        $completeListeners = $dispatcher->getListeners('request.complete');

        $this->assertCount(1, $progressListeners);
        $this->assertCount(1, $completeListeners);
        $this->assertInstanceOf('Closure', reset($progressListeners));
        $this->assertInstanceOf('Closure', reset($completeListeners));
    }

    public function testProgressListenerIsRemovedOnRequestComplete()
    {
        $output = new BufferedOutput;
        $client = new Client;
        $helper = new GuzzleDownloadProgress($client);

        $helper->enableDownloadProgress($output);

        $dispatcher = $client->getEventDispatcher();
        $dispatcher->dispatch('request.complete', new Event);

        $this->assertCount(0, $dispatcher->getListeners('curl.callback.progress'));
        $this->assertCount(1, $dispatcher->getListeners('request.complete'));
    }

    public function testProgressListenerUpdatesOutput()
    {
        $output = new BufferedOutput;
        $client = new Client;
        $helper = new GuzzleDownloadProgress($client);

        $helper->enableDownloadProgress($output);
        $dispatcher = $client->getEventDispatcher();

        // first event
        $event = new Event(['download_size' => 1000]);
        $dispatcher->dispatch('curl.callback.progress', $event);
        $this->assertSame("\x0DPercentage complete: 0%", $output->fetch());

        // second event
        $event = new Event(['downloaded' => 630, 'download_size' => 1000]);
        $dispatcher->dispatch('curl.callback.progress', $event);
        $this->assertSame("\x0DPercentage complete: 63%", $output->fetch());

        // last event
        $event = new Event(['downloaded' => 1000, 'download_size' => 1000]);
        $dispatcher->dispatch('curl.callback.progress', $event);
        $this->assertSame("\x0DPercentage complete: 100%\n", $output->fetch());
    }
}
