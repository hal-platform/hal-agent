<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticBeanstalk\Steps;

use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Credential\AWSRoleCredential;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Waiter\Waiter;
use Hal\Agent\Deploy\ElasticBeanstalk\Steps\DeploymentVerifier;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;
use QL\MCP\Common\Time\Clock;

class DeploymentVerifierTest extends MockeryTestCase
{
    public $eb;
    public $loger;
    public $health;
    public $waiter;
    public $interval;

    public function setUp()
    {
        $this->interval = .1;
        $this->eb = Mockery::mock(ElasticBeanstalkClient::class);
        $this->logger = Mockery::mock(EventLogger::class);
        $this->health = Mockery::mock(HealthChecker::class);
        $this->waiter = Mockery::mock(Waiter::class);
        $this->waiter
            ->shouldReceive('withProbeFunctionName')
            ->andReturn($this->waiter)
            ->shouldReceive('withProbeFunctionArgs')
            ->andReturn($this->waiter)
            ->shouldReceive('withSuccessAceptors')
            ->andReturn($this->waiter)
            ->shouldReceive('withValuePath');
    }

    public function testDeployWorksButThenHealthCheckFails()
    {
        $this->health
            ->shouldReceive('__invoke')
            ->andReturn([
                'status' => 'Updating',
                'health' => 'Grey'
            ])
            ->times(1);

        $this->logger
        ->shouldReceive('event')
        ->with('success', 'Deployment finished. Waiting for health check')
        ->once();

        $this->logger
        ->shouldReceive('event')
        ->with('failure', 'Code Deployment', Mockery::any())
        ->once();

        $this->waiter
            ->shouldReceive('wait')
            ->andReturn(true)
            ->once();

        $verifier = new DeploymentVerifier(
            $this->logger,
            $this->health,
            $this->waiter,
            $this->interval
        );

        $actual = $verifier(
            $this->eb,
            'appName',
            'envId'
        );

        $this->assertSame(false, $actual);
    }

    public function testDeployWorksCheckSuccess()
    {
        $this->health
            ->shouldReceive('__invoke')
            ->andReturn([
                'status' => 'Ready',
                'health' => 'Green'
            ]);

        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Deployment finished. Waiting for health check')
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Code Deployment', Mockery::any())
            ->once();

        $this->waiter
            ->shouldReceive('wait')
            ->andReturn(true)
            ->once();

        $verifier = new DeploymentVerifier(
            $this->logger,
            $this->health,
            $this->waiter,
            $this->interval
        );

        $actual = $verifier(
            $this->eb,
            'appName',
            'envId'
        );

        $this->assertSame(true, $actual);
    }

    public function testDeployFails()
    {
        $this->health
            ->shouldReceive('__invoke')
            ->andReturn([
                'status' => 'Updating',
                'health' => 'Grey'
            ]);

        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Deployment finished. Waiting for health check')
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Code Deployment', Mockery::any())
            ->once();

        $this->waiter
            ->shouldReceive('wait')
            ->andReturn(true)
            ->once();

        $verifier = new DeploymentVerifier(
            $this->logger,
            $this->health,
            $this->waiter,
            $this->interval
        );

        $actual = $verifier(
            $this->eb,
            'appName',
            'envId'
        );

        $this->assertSame(false, $actual);
    }

}
