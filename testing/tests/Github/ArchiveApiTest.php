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

    /**
     * @expectedException Exception
     */
    public function testExceptionThrowIfDirectResponseIsNotRedirect()
    {
        $guzzle = new GuzzleClient;
        $guzzle->addSubscriber(new MockPlugin([
            new Response(200)
        ]));

        $client = new Client(new HttpClient([], $guzzle));

        $api = new ArchiveApi($client);

        $api->download('user', 'repo', 'ref', 'path/derp');
    }

    public function testSuccess()
    {
        $guzzle = new GuzzleClient;
        $guzzle->addSubscriber(new MockPlugin([
            new Response(302),
            new Response(200)
        ]));

        $client = new Client(new HttpClient([], $guzzle));
        $api = new ArchiveApi($client);

        $success = $api->download('user', 'repo', 'ref', 'php://memory');
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

        $client = new Client(new HttpClient([], $guzzle));
        $api = new ArchiveApi($client);

        $success = $api->download('user', 'repo', 'ref', 'path/derp');
    }

    public function testMessageBodyGoesToTarget()
    {
        $testFile = __DIR__ . '/test.request';

        $guzzle = new GuzzleClient;
        $guzzle->addSubscriber(new MockPlugin([
            new Response(302),
            new Response(200, null, 'test-body')
        ]));

        $client = new Client(new HttpClient([], $guzzle));
        $api = new ArchiveApi($client);

        $api->download('user', 'repo', 'ref', $testFile);
        $this->assertSame('test-body', file_get_contents($testFile));

        unlink($testFile);
    }
}
