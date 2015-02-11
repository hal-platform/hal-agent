<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class DelegatingBuilderTest extends PHPUnit_Framework_TestCase
{
    public $output;
    public $logger;
    public $container;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->container = Mockery::mock('Symfony\Component\DependencyInjection\ContainerInterface');
    }

    public function testNoDeployerFoundFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), ['system' => 'buildsystem'])
            ->once();

        $deployer = new DelegatingBuilder($this->logger, $this->container, [

        ]);

        $properties = [];
        $actual = $deployer($this->output, 'buildsystem', [], $properties);
        $this->assertSame(false, $actual);
    }

    public function testDeployerServiceNotFoundFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), ['system' => 'buildsystem'])
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.builder', Mockery::any())
            ->andReturnNull();

        $deployer = new DelegatingBuilder($this->logger, $this->container, [
            'buildsystem' => 'service.builder'
        ]);

        $properties = [];
        $actual = $deployer($this->output, 'buildsystem', [], $properties);
        $this->assertSame(false, $actual);
    }

    public function testDeployerServiceIsNotCallableFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), ['system' => 'buildsystem'])
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.builder', Mockery::any())
            ->andReturn('hai');

        $deployer = new DelegatingBuilder($this->logger, $this->container, [
            'buildsystem' => 'service.builder'
        ]);

        $properties = [];
        $actual = $deployer($this->output, 'buildsystem', [], $properties);
        $this->assertSame(false, $actual);
    }

    public function testDeployerSaysFail()
    {
        $this->container
            ->shouldReceive('get')
            ->with('service.build.a', Mockery::any())
            ->andReturn(function() {return 999;});

        $deployer = new DelegatingBuilder($this->logger, $this->container, [
            'buildsystem.a' => 'service.build.a'
        ]);

        $properties = [];
        $actual = $deployer($this->output, 'buildsystem.a', [], $properties);
        $this->assertSame(false, $actual);
        $this->assertSame(999, $deployer->getExitCode());
    }

    public function testSuccess()
    {
        $this->container
            ->shouldReceive('get')
            ->with('service.build.b', Mockery::any())
            ->andReturn(function() {return 0;});

        $deployer = new DelegatingBuilder($this->logger, $this->container, [
            'buildsystem.b' => 'service.build.b'
        ]);

        $properties = [];
        $actual = $deployer($this->output, 'buildsystem.b', [], $properties);
        $this->assertSame(true, $actual);
    }
}
