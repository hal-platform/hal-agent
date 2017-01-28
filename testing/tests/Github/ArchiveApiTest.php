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
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Subscriber\Mock;
use Http\Adapter\Guzzle5\Client as Guzzle5Adapter;
use PHPUnit_Framework_TestCase;

class ArchiveApiTest extends PHPUnit_Framework_TestCase
{
    public function testExceptionThrowIfDirectResponseIsNotRedirect()
    {
        $guzzle = new GuzzleClient;
        $mock = new Mock([
            new Response(200)
        ]);
        $guzzle->getEmitter()->attach($mock);

        $guzzleAdapter = new Guzzle5Adapter($guzzle);
        $httpBuilder = new Builder($guzzleAdapter);

        $client = new EnterpriseClient($httpBuilder, null, 'http://git');

        $api = new ArchiveApi($client);

        $this->expectException(GitHubException::class);
        $api->download('user', 'repo', 'ref', 'path/derp');
    }

    public function testSuccess()
    {
        $guzzle = new GuzzleClient;
        $mock = new Mock([
            new Response(302, ['Location' => 'http://foo']),
            new Response(200)
        ]);
        $guzzle->getEmitter()->attach($mock);

        $guzzleAdapter = new Guzzle5Adapter($guzzle);
        $httpBuilder = new Builder($guzzleAdapter);

        $client = new EnterpriseClient($httpBuilder, null, 'http://git');

        $api = new ArchiveApi($client);

        $success = $api->download('user', 'repo', 'ref', 'php://memory');
        $this->assertTrue($success);
    }

    public function testFailureThrowsException()
    {
        $guzzle = new GuzzleClient;
        $mock = new Mock([
            new Response(302, ['Location' => 'http://foo']),
            new Response(503)
        ]);
        $guzzle->getEmitter()->attach($mock);

        $guzzleAdapter = new Guzzle5Adapter($guzzle);
        $httpBuilder = new Builder($guzzleAdapter);

        $client = new EnterpriseClient($httpBuilder, null, 'http://git');

        $api = new ArchiveApi($client);

        $this->expectException(RuntimeException::class);
        $success = $api->download('user', 'repo', 'ref', 'path/derp');
    }

    public function testMessageBodyGoesToTarget()
    {
        $testFile = __DIR__ . '/test.request';

        $guzzle = new GuzzleClient;
        $mock = new Mock([
            new Response(302, ['Location' => 'http://foo']),
            new Response(200, [], Stream::factory('test-body'))
        ]);
        $guzzle->getEmitter()->attach($mock);

        $guzzleAdapter = new Guzzle5Adapter($guzzle);
        $httpBuilder = new Builder($guzzleAdapter);

        $client = new EnterpriseClient($httpBuilder, null, 'http://git');

        $api = new ArchiveApi($client);
        $api->download('user', 'repo', 'ref', $testFile);
        $this->assertSame('test-body', file_get_contents($testFile));

        unlink($testFile);
    }
}
