<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Github;

use Github\Exception\RuntimeException;
use Github\HttpClient\Builder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Stream\Stream;
use Http\Adapter\Guzzle6\Client as Guzzle6Adapter;
use PHPUnit_Framework_TestCase;

class ArchiveApiTest extends PHPUnit_Framework_TestCase
{
    public function testExceptionThrowIfDirectResponseIsNotRedirect()
    {
        $mock = new MockHandler([
            new Response(200)
        ]);
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create($mock)]);

        $guzzleAdapter = new Guzzle6Adapter($guzzle);
        $httpBuilder = new Builder($guzzleAdapter);

        $client = new EnterpriseClient($httpBuilder, null, 'http://git');

        $api = new ArchiveApi($client);

        $this->expectException(GitHubException::class);
        $api->download('user', 'repo', 'ref', 'path/derp');
    }

    public function testSuccess()
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://foo']),
            new Response(200)
        ]);
        $guzzle = new GuzzleClient(['handler' => $mock]);

        $guzzleAdapter = new Guzzle6Adapter($guzzle);
        $httpBuilder = new Builder($guzzleAdapter);

        $client = new EnterpriseClient($httpBuilder, null, 'http://git');

        $api = new ArchiveApi($client);

        $success = $api->download('user', 'repo', 'ref', 'php://memory');
        $this->assertTrue($success);
    }

    public function testFailureThrowsException()
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://foo']),
            new Response(503)
        ]);
        $guzzle = new GuzzleClient(['handler' => $mock]);

        $guzzleAdapter = new Guzzle6Adapter($guzzle);
        $httpBuilder = new Builder($guzzleAdapter);

        $client = new EnterpriseClient($httpBuilder, null, 'http://git');

        $api = new ArchiveApi($client);

        $this->expectException(RuntimeException::class);
        $success = $api->download('user', 'repo', 'ref', 'path/derp');
    }

    public function testMessageBodyGoesToTarget()
    {
        $testFile = __DIR__ . '/test.request';

        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://foo']),
            new Response(200, [], Stream::factory('test-body'))
        ]);

        $guzzle = new GuzzleClient(['handler' => $mock]);

        $guzzleAdapter = new Guzzle6Adapter($guzzle);
        $httpBuilder = new Builder($guzzleAdapter);

        $client = new EnterpriseClient($httpBuilder, null, 'http://git');

        $api = new ArchiveApi($client);
        $api->download('user', 'repo', 'ref', $testFile);
        $this->assertSame('test-body', file_get_contents($testFile));

        unlink($testFile);
    }
}
