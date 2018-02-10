<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\IOTestCase;
use Hal\Agent\JobPlatformInterface;
use Hal\Core\Entity\JobType\Release;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DeployerTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $container;
    public $release;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->container = Mockery::mock(ContainerInterface::class);

        $this->release = Mockery::mock(Release::class);
    }

    public function testNoDeployPlatformFoundFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Invalid deployment platform specified', ['platform' => 'my-platform'])
            ->once();

        $deployer = new Deployer($this->logger, $this->container, []);

        $properties = $config = [];
        $actual = $deployer($this->release, $this->io(), 'my-platform', $config, $properties);

        $this->assertSame(false, $actual);
    }

    public function testDeployerServiceNotFoundFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Invalid deployment platform specified', ['platform' => 'my-platform'])
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.deployer', Mockery::any())
            ->andReturnNull();

        $deployer = new Deployer($this->logger, $this->container, [
            'my-platform' => 'service.deployer'
        ]);

        $properties = $config = [];
        $actual = $deployer($this->release, $this->io(), 'my-platform', $config, $properties);
        $this->assertSame(false, $actual);
    }

    public function testDeployerServiceIsNotCallableFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), ['platform' => 'my-platform'])
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.deployer', Mockery::any())
            ->andReturn('hai');

        $deployer = new Deployer($this->logger, $this->container, [
            'my-platform' => 'service.deployer'
        ]);

        $properties = $config = [];
        $actual = $deployer($this->release, $this->io(), 'my-platform', $config, $properties);
        $this->assertSame(false, $actual);
    }

    public function testDeployerSaysFail()
    {
        $platform = Mockery::mock(JobPlatformInterface::class, [
            'setIO' => null
        ]);

        $platform
            ->shouldReceive('__invoke')
            ->with($this->release, ['this_is_project_config'], ['this_is_agent_config'])
            ->once()
            ->andReturn(false);

        $this->logger
            ->shouldReceive('setStage')
            ->with('running')
            ->once();

        $this->container
            ->shouldReceive('get')
            ->with('service.deploy.a', Mockery::any())
            ->once()
            ->andReturn($platform);

        $deployer = new Deployer($this->logger, $this->container, [
            'my-platform.a' => 'service.deploy.a'
        ]);

        $properties = ['this_is_agent_config'];
        $config = ['this_is_project_config'];

        $actual = $deployer($this->release, $this->io(), 'my-platform.a', $config, $properties);

        $this->assertSame(false, $actual);
    }

    public function testSuccess()
    {
        $platform = Mockery::mock(JobPlatformInterface::class, [
            'setIO' => null
        ]);

        $platform
            ->shouldReceive('__invoke')
            ->with($this->release, ['this_is_project_config'], ['this_is_agent_config'])
            ->once()
            ->andReturn(true);

        $this->logger
            ->shouldReceive('setStage')
            ->with('running')
            ->once();


        $this->container
            ->shouldReceive('get')
            ->with('service.deploy.b', Mockery::any())
            ->once()
            ->andReturn($platform);

        $deployer = new Deployer($this->logger, $this->container, [
            'my-platform.b' => 'service.deploy.b'
        ]);

        $properties = ['this_is_agent_config'];
        $config = ['this_is_project_config'];

        $actual = $deployer($this->release, $this->io(), 'my-platform.b', $config, $properties);

        $this->assertSame(true, $actual);
    }
}
