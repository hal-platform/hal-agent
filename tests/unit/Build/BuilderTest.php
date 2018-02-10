<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\PlatformHandlerStub;
use Hal\Agent\Testing\IOTestCase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BuilderTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $container;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->container = Mockery::mock(ContainerInterface::class);
    }

    public function testNoBuildPlatformFoundFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Invalid build platform specified', ['platform' => 'my-platform'])
            ->once();

        $builder = new Builder($this->logger, $this->container, []);

        $properties = $config = [];
        $actual = $builder($this->io(), 'my-platform', $config, $properties);

        $this->assertSame(false, $actual);
    }

    public function testBuilderServiceNotFoundFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Invalid build platform specified', ['platform' => 'my-platform'])
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.builder', Mockery::any())
            ->andReturnNull();

        $builder = new Builder($this->logger, $this->container, [
            'my-platform' => 'service.builder'
        ]);

        $properties = $config = [];
        $actual = $builder($this->io(), 'my-platform', $config, $properties);
        $this->assertSame(false, $actual);
    }

    public function testBuilderServiceIsNotCallableFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), ['platform' => 'my-platform'])
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.builder', Mockery::any())
            ->andReturn('hai');

        $builder = new Builder($this->logger, $this->container, [
            'my-platform' => 'service.builder'
        ]);

        $properties = $config = [];
        $actual = $builder($this->io(), 'my-platform', $config, $properties);
        $this->assertSame(false, $actual);
    }

    public function testBuilderSaysFail()
    {
        $platform = Mockery::mock(BuildPlatformInterface::class, [
            'setIO' => null
        ]);

        $platform
            ->shouldReceive('__invoke')
            ->with(['this_is_project_config'], ['this_is_agent_config'])
            ->once()
            ->andReturn(false);

        $this->logger
            ->shouldReceive('setStage')
            ->with('running')
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.build.a', Mockery::any())
            ->once()
            ->andReturn($platform);

        $builder = new Builder($this->logger, $this->container, [
            'my-platform.a' => 'service.build.a'
        ]);

        $properties = ['this_is_agent_config'];
        $config = ['this_is_project_config'];

        $actual = $builder($this->io(), 'my-platform.a', $config, $properties);

        $this->assertSame(false, $actual);
    }

    public function testSuccess()
    {
        $platform = Mockery::mock(BuildPlatformInterface::class, [
            'setIO' => null
        ]);

        $platform
            ->shouldReceive('__invoke')
            ->with(['this_is_project_config'], ['this_is_agent_config'])
            ->once()
            ->andReturn(true);

        $this->logger
            ->shouldReceive('setStage')
            ->with('running')
            ->once();


        $this->container
            ->shouldReceive('get')
            ->with('service.build.b', Mockery::any())
            ->once()
            ->andReturn($platform);

        $builder = new Builder($this->logger, $this->container, [
            'my-platform.b' => 'service.build.b'
        ]);

        $properties = ['this_is_agent_config'];
        $config = ['this_is_project_config'];

        $actual = $builder($this->io(), 'my-platform.b', $config, $properties);

        $this->assertSame(true, $actual);
    }
}
