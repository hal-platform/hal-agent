<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Github;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Hal\Agent\Testing\MockeryTestCase;
use function GuzzleHttp\Psr7\stream_for;

class ArchiveApiTest extends MockeryTestCase
{
    public $targetFile;

    public function setUp()
    {
        $this->targetFile = __DIR__ . '/test.response';
    }

    public function tearDown()
    {
        @unlink($this->targetFile);
    }

    public function testSuccess()
    {
        $mock = HandlerStack::create(new MockHandler([
            new Response(200)
        ]));

        $guzzle = new GuzzleClient(['handler' => $mock]);
        $api = new ArchiveApi($guzzle, 'http://git');

        $success = $api->download('user', 'repo', 'ref', 'php://memory');
        $this->assertTrue($success);
    }


    public function testSuccessWithRedirect()
    {
        $mock = HandlerStack::create(new MockHandler([
            new Response(302, ['Location' => 'http://foo']),
            new Response(200)
        ]));

        $guzzle = new GuzzleClient(['handler' => $mock]);
        $api = new ArchiveApi($guzzle, 'http://github.local');

        $success = $api->download('user', 'repo', 'ref', $this->targetFile);
        $this->assertTrue($success);
    }

    public function testSuccessWithMultipleRedirect()
    {
        $mock = HandlerStack::create(new MockHandler([
            new Response(302, ['Location' => 'http://foo']),
            new Response(302, ['Location' => 'https://foo']),
            new Response(200)
        ]));

        $guzzle = new GuzzleClient(['handler' => $mock]);
        $api = new ArchiveApi($guzzle, 'http://git');

        $success = $api->download('user', 'repo', 'ref', 'php://memory');
        $this->assertTrue($success);
    }

    public function testFailureThrowsException()
    {
        $this->expectException(GitHubException::class);

        $mock = HandlerStack::create(new MockHandler([
            new Response(302, ['Location' => 'http://foo']),
            new Response(503)
        ]));

        $guzzle = new GuzzleClient(['handler' => $mock]);
        $api = new ArchiveApi($guzzle, 'http://git');

        $success = $api->download('user', 'repo', 'ref', 'path/derp');
    }

    public function testMessageBodyGoesToTarget()
    {
        $mock = HandlerStack::create(new MockHandler([
            new Response(302, ['Location' => 'http://foo']),
            new Response(200, [], stream_for('test-body'))
        ]));

        $guzzle = new GuzzleClient(['handler' => $mock]);
        $api = new ArchiveApi($guzzle, 'http://git');

        $api->download('user', 'repo', 'ref', $this->targetFile);
        $this->assertSame('test-body', file_get_contents($this->targetFile));
    }
}
