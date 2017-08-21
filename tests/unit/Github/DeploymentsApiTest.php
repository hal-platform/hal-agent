<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Github;

use GuzzleHttp\Client as GuzzleClient;
use function GuzzleHttp\Psr7\stream_for;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Psr\Http\Message\ResponseInterface;

class DeploymentsApiTest extends MockeryTestCase
{
    public function testCreateDeploymentSuccess()
    {
        $response = Mockery::mock(ResponseInterface::class, [
            'getStatusCode' => 200,
            'getBody' => stream_for('{"id": 55}')
        ]);

        $payload = null;
        $guzzle = Mockery::mock(GuzzleClient::class);
        $guzzle
            ->shouldReceive('post')
            ->with(
                'http://gitapi/repos/owner1/repo1/deployments',
                Mockery::on(function($v) use (&$payload) {
                    $payload = $v;
                    return true;
                })
            )
            ->andReturn($response);

        $api = new DeploymentsApi($guzzle, 'http://gitapi/');
        $result = $api->createDeployment(
            'owner1',
            'repo1',
            'token1',
            'master',
            'test'
        );

        $expectedBody = [
            'ref' => 'master',
            'environment' => 'test',
            'auto_merge' => false
        ];

        $this->assertSame('token token1', $payload['headers']['Authorization']);
        $this->assertSame($expectedBody, json_decode($payload['body'], true));

        $this->assertSame(55, $result);
    }

    public function testCreateDeploymentStatusSuccess()
    {
        $response = Mockery::mock(ResponseInterface::class, [
            'getStatusCode' => 200,
            'getBody' => stream_for('{"id": 55}')
        ]);

        $payload = null;
        $guzzle = Mockery::mock(GuzzleClient::class);
        $guzzle
            ->shouldReceive('post')
            ->with(
                'http://gitapi/repos/owner1/repo1/deployments/66/statuses',
                Mockery::on(function($v) use (&$payload) {
                    $payload = $v;
                    return true;
                })
            )
            ->andReturn($response);

        $api = new DeploymentsApi($guzzle, 'http://gitapi/');
        $result = $api->createDeploymentStatus(
            'owner1',
            'repo1',
            'token1',
            66,
            'pending',
            'http://myapp/page',
            'test description'
        );

        $expectedBody = [
            'state' => 'pending',
            'target_url' => 'http://myapp/page',
            'description' => 'test description'
        ];

        $this->assertSame('token token1', $payload['headers']['Authorization']);
        $this->assertSame($expectedBody, json_decode($payload['body'], true));

        $this->assertSame(true, $result);
    }
}
