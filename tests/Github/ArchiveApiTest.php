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
use PHPUnit_Framework_TestCase;

class ArchiveApiTest extends PHPUnit_Framework_TestCase
{
    public function testSuccess()
    {
        $guzzle = new GuzzleClient;
        $guzzle->addSubscriber(new MockPlugin([
            new Response(200)
        ]));

        $api = new ArchiveApi(new Client(new HttpClient([], $guzzle)));

        $success = $api->download('user', 'repo', 'ref', 'php://temp');
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

        $api = new ArchiveApi(new Client(new HttpClient([], $guzzle)));

        $success = $api->download('user', 'repo', 'ref', 'php://temp');
    }

    public function testMessageBodyGoesToTarget()
    {
        $guzzle = new GuzzleClient;
        $guzzle->addSubscriber(new MockPlugin([
            new Response(200, null, 'test-body')
        ]));

        $api = new ArchiveApi(new Client(new HttpClient([], $guzzle)));

        ob_start();
        $api->download('user', 'repo', 'ref', 'php://output');
        $buffer = ob_get_clean();

        $this->assertSame('test-body', $buffer);
    }
}
