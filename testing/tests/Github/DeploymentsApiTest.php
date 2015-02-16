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

class DeploymentsApiTest extends PHPUnit_Framework_TestCase
{
    public function testCreateDeploymentSuccess()
    {
        $response = Mockery::mock('GuzzleHttp\Message\ResponseInterface', [
            'getStatusCode' => 200,
            'json' => [
                'id' => 55
            ]
        ]);

        $payload = null;
        $guzzle = Mockery::mock('GuzzleHttp\Client');
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
        $response = Mockery::mock('GuzzleHttp\Message\ResponseInterface', [
            'getStatusCode' => 200,
            'json' => [
                'id' => 55
            ]
        ]);

        $payload = null;
        $guzzle = Mockery::mock('GuzzleHttp\Client');
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
