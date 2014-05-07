<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Github;

use Github\Client;
use Github\HttpClient\HttpClient;
use Guzzle\Http\Client as GuzzleClient;
use Guzzle\Http\Message\Response;
use Guzzle\Plugin\Mock\MockPlugin;
use Mockery;
use PHPUnit_Framework_TestCase;

class ArchiveApiTest extends PHPUnit_Framework_TestCase
{
    public function testSuccess()
    {
        $guzzle = new GuzzleClient;
        $guzzle->addSubscriber(new MockPlugin([
            new Response(200)
        ]));

        $filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');
        $filesystem->shouldReceive('dumpFile');

        $api = new ArchiveApi(new Client(new HttpClient([], $guzzle)), $filesystem);

        $success = $api->download('user', 'repo', 'ref', 'path/derp');
        $this->assertTrue($success);
    }

    /**
     * @expectedException Github\Exception\RuntimeException
     */
    public function testFailureThrowsException()
    {
        $guzzle = new GuzzleClient;
        $guzzle->addSubscriber(new MockPlugin([
            new Response(503)
        ]));

        $filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');

        $api = new ArchiveApi(new Client(new HttpClient([], $guzzle)), $filesystem);

        $success = $api->download('user', 'repo', 'ref', 'path/derp');
    }

    public function testMessageBodyGoesToTarget()
    {
        $guzzle = new GuzzleClient;
        $guzzle->addSubscriber(new MockPlugin([
            new Response(200, null, 'test-body')
        ]));

        $body = null;

        $filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');
        $filesystem
            ->shouldReceive('dumpFile')
            ->with('path/derp', Mockery::on(function($v) use (&$body) {
                $body = $v;
                return true;
            }))
            ->once();

        $api = new ArchiveApi(new Client(new HttpClient([], $guzzle)), $filesystem);

        $api->download('user', 'repo', 'ref', 'path/derp');
        $this->assertSame('test-body', (string) $body);
    }
}
