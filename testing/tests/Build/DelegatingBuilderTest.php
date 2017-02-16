<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;
use Hal\Agent\Command\IO;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\BuildHandlerStub;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DelegatingBuilderTest extends PHPUnit_Framework_TestCase
{
    public $output;
    public $io;
    public $logger;
    public $container;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->io = new IO(Mockery::mock(InputInterface::class), $this->output);

        $this->logger = Mockery::mock(EventLogger::class);
        $this->container = Mockery::mock(ContainerInterface::class);
    }

    public function testNoDeployerFoundFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), ['system' => 'buildsystem'])
            ->once();

        $deployer = new DelegatingBuilder($this->logger, $this->container, []);

        $properties = [];
        $actual = $deployer($this->io, 'buildsystem', [], $properties);
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
        $actual = $deployer($this->io, 'buildsystem', [], $properties);
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
        $actual = $deployer($this->io, 'buildsystem', [], $properties);
        $this->assertSame(false, $actual);
    }

    public function testDeployerSaysFail()
    {
        $stub = new BuildHandlerStub;
        $stub->response = 999;

        $this->container
            ->shouldReceive('get')
            ->with('service.build.a', Mockery::any())
            ->andReturn($stub);

        $deployer = new DelegatingBuilder($this->logger, $this->container, [
            'buildsystem.a' => 'service.build.a'
        ]);

        $properties = [];
        $actual = $deployer($this->io, 'buildsystem.a', [], $properties);
        $this->assertSame(false, $actual);
        $this->assertSame('Unknown build failure', $deployer->getFailureMessage());
    }

    public function testSuccess()
    {
        $stub = new BuildHandlerStub;
        $stub->response = 0;

        $this->container
            ->shouldReceive('get')
            ->with('service.build.b', Mockery::any())
            ->andReturn($stub);

        $deployer = new DelegatingBuilder($this->logger, $this->container, [
            'buildsystem.b' => 'service.build.b'
        ]);

        $properties = [];
        $actual = $deployer($this->io, 'buildsystem.b', [], $properties);
        $this->assertSame(true, $actual);
    }
}
