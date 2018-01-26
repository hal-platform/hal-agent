<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Testing\PlatformHandlerStub;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Command\IO;
use Hal\Agent\Logger\EventLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DelegatingBuilderTest extends MockeryTestCase
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

    public function testNoBuildPlatformFoundFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), ['platform' => 'buildsystem'])
            ->once();

        $builder = new DelegatingBuilder($this->logger, $this->container, []);

        $properties = [];
        $actual = $builder($this->io, 'buildsystem', 'default', [], $properties);
        $this->assertSame(false, $actual);
    }

    public function testBuilderServiceNotFoundFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), ['platform' => 'buildsystem'])
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.builder', Mockery::any())
            ->andReturnNull();

        $builder = new DelegatingBuilder($this->logger, $this->container, [
            'buildsystem' => 'service.builder'
        ]);

        $properties = [];
        $actual = $builder($this->io, 'buildsystem', 'default', [], $properties);
        $this->assertSame(false, $actual);
    }

    public function testBuilderServiceIsNotCallableFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), ['platform' => 'buildsystem'])
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.builder', Mockery::any())
            ->andReturn('hai');

        $builder = new DelegatingBuilder($this->logger, $this->container, [
            'buildsystem' => 'service.builder'
        ]);

        $properties = [];
        $actual = $builder($this->io, 'buildsystem', 'default', [], $properties);
        $this->assertSame(false, $actual);
    }

    public function testBuilderSaysFail()
    {
        $stub = new PlatformHandlerStub;
        $stub->response = 999;

        $this->container
            ->shouldReceive('get')
            ->with('service.build.a', Mockery::any())
            ->andReturn($stub);

        $builder = new DelegatingBuilder($this->logger, $this->container, [
            'buildsystem.a' => 'service.build.a'
        ]);

        $properties = [];
        $actual = $builder($this->io, 'buildsystem.a', 'image', [], $properties);
        $this->assertSame(false, $actual);
        $this->assertSame('Unknown build failure', $builder->getFailureMessage());
    }

    public function testSuccess()
    {
        $stub = new PlatformHandlerStub;
        $stub->response = 0;

        $this->container
            ->shouldReceive('get')
            ->with('service.build.b', Mockery::any())
            ->andReturn($stub);

        $builder = new DelegatingBuilder($this->logger, $this->container, [
            'buildsystem.b' => 'service.build.b'
        ]);

        $properties = [];
        $actual = $builder($this->io, 'buildsystem.b', 'default', [], $properties);
        $this->assertSame(true, $actual);
    }
}
