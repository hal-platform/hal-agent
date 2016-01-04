<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\CodeDeploy;

use Aws\CommandInterface;
use Aws\CodeDeploy\CodeDeployClient;
use Aws\CodeDeploy\Exception\CodeDeployException;
use Aws\Result;
use Mockery;
use PHPUnit_Framework_TestCase;

class HealthCheckerTest extends PHPUnit_Framework_TestCase
{
    public $cd;

    public function setUp()
    {
        $this->cd = Mockery::mock(CodeDeployClient::CLASS);
    }

    public function testRecentDeploymentRevisionHealth()
    {
        $deploymentsResult = new Result([
            'deployments' => [
                '1234',
                '5678'
            ],
            'nextToken' => 'dontcare',
        ]);

        $infoResult = new Result([
            'deploymentInfo' => [
                'applicationName' => 'appName',
                'deploymentGroupName' => 'groupName',
                'deploymentId' => '1234',
                'deploymentOverview' => [
                    'Failed' => 0,
                    'InProgress' => 0,
                    'Pending' => 0,
                    'Skipped' => 0,
                    'Succeeded' => 5,
                ],

                'status' => 'Succeeded',
            ]
        ]);

        $this->cd
            ->shouldReceive('listDeployments')
            ->with([
                'applicationName' => 'appName',
                'deploymentGroupName' => 'groupName'
            ])
            ->andReturn($deploymentsResult);
        $this->cd
            ->shouldReceive('getDeployment')
            ->with([
                'deploymentId' => '1234',
            ])
            ->andReturn($infoResult);

        $checker = new HealthChecker;
        $actual = $checker($this->cd, 'appName', 'groupName');

        $expectedOverview = [
            'Failed' => 0,
            'InProgress' => 0,
            'Pending' => 0,
            'Skipped' => 0,
            'Succeeded' => 5
        ];

        $this->assertSame('Succeeded', $actual['status']);
        $this->assertSame($expectedOverview, $actual['overview']);
        $this->assertSame(null, $actual['error']);
    }

    public function testNoDeploymentsReturnsAsNonstandardNone()
    {
        $deploymentsResult = new Result([
            'deployments' => [],
            'nextToken' => 'dontcare',
        ]);

        $this->cd
            ->shouldReceive('listDeployments')
            ->with([
                'applicationName' => 'appName',
                'deploymentGroupName' => 'groupName'
            ])
            ->andReturn($deploymentsResult);

        $checker = new HealthChecker;
        $actual = $checker($this->cd, 'appName', 'groupName');

        $this->assertSame('None', $actual['status']);
        $this->assertSame(null, $actual['overview']);
        $this->assertSame(null, $actual['error']);
    }

    public function testClientErrorReturnsInvalid()
    {
        $ex = new CodeDeployException('msg', Mockery::mock(CommandInterface::CLASS));
        $this->cd
            ->shouldReceive('listDeployments')
            ->andThrow($ex);

        $checker = new HealthChecker;
        $actual = $checker($this->cd, 'appName', 'groupName');

        $this->assertSame('Invalid', $actual['status']);
        $this->assertSame(null, $actual['overview']);
        $this->assertSame(null, $actual['error']);
    }
}
