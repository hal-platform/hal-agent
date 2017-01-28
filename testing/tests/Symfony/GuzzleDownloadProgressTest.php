<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Symfony;

use GuzzleHttp\Client;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\EmitterInterface;
use GuzzleHttp\Event\ProgressEvent;
use GuzzleHttp\Transaction;
use Mockery;
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

        /** @var EmitterInterface $dispatcher */
        $dispatcher = $client->getEmitter();
        $progressListeners = $dispatcher->listeners('progress');
        $completeListeners = $dispatcher->listeners('complete');

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

        /** @var EmitterInterface $dispatcher */
        $dispatcher = $client->getEmitter();
        $mockTransaction = Mockery::mock(Transaction::class);
        $mockTransaction->client = $client;
        $dispatcher->emit('complete', new CompleteEvent($mockTransaction));

        $this->assertCount(0, $dispatcher->listeners('progress'));
        $this->assertCount(1, $dispatcher->listeners('complete'));
    }

    public function testProgressListenerUpdatesOutput()
    {
        $output = new BufferedOutput;
        $client = new Client;
        $helper = new GuzzleDownloadProgress($client);

        $helper->enableDownloadProgress($output);
        /** @var EmitterInterface $dispatcher */
        $dispatcher = $client->getEmitter();

        // first event
        $event = Mockery::mock(ProgressEvent::class)->shouldIgnoreMissing();
        $event->downloadSize = 1000;
        $dispatcher->emit('progress', $event);
        $this->assertSame("\x0DPercentage complete: 0%", $output->fetch());

        // second event
        $event = Mockery::mock(ProgressEvent::class)->shouldIgnoreMissing();
        $event->downloaded = 630;
        $event->downloadSize = 1000;
        $dispatcher->emit('progress', $event);
        $this->assertSame("\x0DPercentage complete: 63%", $output->fetch());

        // last event
        $event = Mockery::mock(ProgressEvent::class)->shouldIgnoreMissing();
        $event->downloaded = 1000;
        $event->downloadSize = 1000;
        $dispatcher->emit('progress', $event);
        $this->assertSame("\x0DPercentage complete: 100%\n", $output->fetch());
    }
}
