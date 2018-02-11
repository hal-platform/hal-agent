<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\IOTestCase;
use Hal\Core\Entity\JobType\Build;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\DependencyInjection\ContainerInterface;

class JobRunnerTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $container;
    public $build;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->container = Mockery::mock(ContainerInterface::class);

        $this->build = new Build;
    }

    public function testNoBuildPlatformFoundFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Invalid job platform specified', ['platform' => 'my-platform', 'validPlatforms' => 'No platforms configured'])
            ->once();

        $builder = new JobRunner($this->logger, $this->container, []);

        $properties = $config = [];
        $actual = $builder($this->build, $this->io(), 'my-platform', $config, $properties);

        $this->assertSame(false, $actual);
    }

    public function testBuilderServiceNotFoundFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Invalid job platform specified', ['platform' => 'my-platform', 'validPlatforms' => ['my-platform']])
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.builder', Mockery::any())
            ->andReturnNull();

        $builder = new JobRunner($this->logger, $this->container, [
            'my-platform' => 'service.builder'
        ]);

        $properties = $config = [];
        $actual = $builder($this->build, $this->io(), 'my-platform', $config, $properties);
        $this->assertSame(false, $actual);
    }

    public function testBuilderServiceIsNotCallableFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with(
                'failure',
                Mockery::any(),
                [
                    'platform' => 'my-platform',
                    'validPlatforms' => ['my-platform', 'your-platform', 'test-builder']
                ]
            )
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.builder', Mockery::any())
            ->andReturn('hai');

        $builder = new JobRunner($this->logger, $this->container, [
            'my-platform' => 'service.builder',
            'your-platform' => 'service.builder2',
            'test-builder' => 'service.builder3'
        ]);

        $properties = $config = [];
        $actual = $builder($this->build, $this->io(), 'my-platform', $config, $properties);
        $this->assertSame(false, $actual);
    }

    public function testBuilderSaysFail()
    {
        $platform = Mockery::mock(JobPlatformInterface::class, [
            'setIO' => null
        ]);

        $platform
            ->shouldReceive('__invoke')
            ->with($this->build, ['this_is_project_config'], ['this_is_agent_config'])
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

        $builder = new JobRunner($this->logger, $this->container, [
            'my-platform.a' => 'service.build.a'
        ]);

        $properties = ['this_is_agent_config'];
        $config = ['this_is_project_config'];

        $actual = $builder($this->build, $this->io(), 'my-platform.a', $config, $properties);

        $this->assertSame(false, $actual);
    }

    public function testSuccess()
    {
        $platform = Mockery::mock(JobPlatformInterface::class, [
            'setIO' => null
        ]);

        $platform
            ->shouldReceive('__invoke')
            ->with($this->build, ['this_is_project_config'], ['this_is_agent_config'])
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

        $builder = new JobRunner($this->logger, $this->container, [
            'my-platform.b' => 'service.build.b'
        ]);

        $properties = ['this_is_agent_config'];
        $config = ['this_is_project_config'];

        $actual = $builder($this->build, $this->io(), 'my-platform.b', $config, $properties);

        $this->assertSame(true, $actual);
    }
}
