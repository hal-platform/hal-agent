<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push;

use Mockery;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\DeployerStub;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DelegatingDeployerTest extends PHPUnit_Framework_TestCase
{
    public $output;
    public $logger;
    public $container;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock(EventLogger::class);
        $this->container = Mockery::mock(ContainerInterface::class);
    }

    public function testNoDeployerFoundFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), ['method' => 'pushmethod'])
            ->once();

        $deployer = new DelegatingDeployer($this->logger, $this->container, [

        ]);

        $properties = [];
        $actual = $deployer($this->output, 'pushmethod', $properties);
        $this->assertSame(false, $actual);
    }

    public function testDeployerServiceNotFoundFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), ['method' => 'pushmethod'])
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.pushmethod', Mockery::any())
            ->andReturnNull();

        $deployer = new DelegatingDeployer($this->logger, $this->container, [
            'pushmethod' => 'service.pushmethod'
        ]);

        $properties = [];
        $actual = $deployer($this->output, 'pushmethod', $properties);
        $this->assertSame(false, $actual);
    }

    public function testDeployerServiceIsNotCallableFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), ['method' => 'pushmethod'])
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.pushmethod', Mockery::any())
            ->andReturn('hai');

        $deployer = new DelegatingDeployer($this->logger, $this->container, [
            'pushmethod' => 'service.pushmethod'
        ]);

        $properties = [];
        $actual = $deployer($this->output, 'pushmethod', $properties);
        $this->assertSame(false, $actual);
    }

    public function testDeployerSaysFail()
    {
        $stub = new DeployerStub;
        $stub->response = 999;

        $this->container
            ->shouldReceive('get')
            ->with('service.pushmethod', Mockery::any())
            ->andReturn($stub);

        $this->logger
            ->shouldReceive('setStage')
            ->with('pushing')
            ->once();

        $deployer = new DelegatingDeployer($this->logger, $this->container, [
            'pushmethod' => 'service.pushmethod'
        ]);

        $properties = [];
        $actual = $deployer($this->output, 'pushmethod', $properties);
        $this->assertSame(false, $actual);
        $this->assertSame('Unknown deployment failure', $deployer->getFailureMessage());
    }

    public function testSuccess()
    {
        $stub = new DeployerStub;
        $stub->response = 0;

        $this->container
            ->shouldReceive('get')
            ->with('service.pushmethod', Mockery::any())
            ->andReturn($stub);

        $this->logger
            ->shouldReceive('setStage')
            ->with('pushing')
            ->once();

        $deployer = new DelegatingDeployer($this->logger, $this->container, [
            'pushmethod' => 'service.pushmethod'
        ]);

        $properties = [];
        $actual = $deployer($this->output, 'pushmethod', $properties);
        $this->assertSame(true, $actual);
    }
}
