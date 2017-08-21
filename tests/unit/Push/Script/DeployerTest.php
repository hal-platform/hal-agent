<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\Script;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Command\IO;
use Hal\Agent\Build\DelegatingBuilder;
use Symfony\Component\Console\Output\BufferedOutput;

class DeployerTest extends MockeryTestCase
{
    public $output;
    public $logger;
    public $builder;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock(EventLogger::class);
        $this->builder = Mockery::mock(DelegatingBuilder::class);
    }

    public function testSuccess()
    {
        $properties = [
            'script' => [],
            'configuration' => [
                'system' => 'unix',
                'pre_push' => [],
                'deploy' => ['cmd1', 'cmd2'],
                'post_push' => [],
            ]
        ];

        $this->builder
            ->shouldReceive('__invoke')
            ->with(Mockery::type(IO::class), 'unix', ['cmd1', 'cmd2'], $properties)
            ->once()
            ->andReturn(true);

        $deployer = new Deployer($this->logger, $this->builder);
        $deployer->setOutput($this->output);

        $actual = $deployer($properties);
        $this->assertSame(0, $actual);
    }

    public function testSuccessWithSkippedCommands()
    {
        $properties = [
            'script' => [],
            'configuration' => [
                'system' => 'unix',
                'pre_push' => ['push1'],
                'deploy' => ['cmd1', 'cmd2'],
                'post_push' => ['push2'],
            ]
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('info', Deployer::SKIP_PRE_PUSH)
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('info', Deployer::SKIP_POST_PUSH)
            ->once();

        $this->builder
            ->shouldReceive('__invoke')
            ->with(Mockery::type(IO::class), 'unix', ['cmd1', 'cmd2'], $properties)
            ->once()
            ->andReturn(true);

        $deployer = new Deployer($this->logger, $this->builder);
        $deployer->setOutput($this->output);

        $actual = $deployer($properties);
        $this->assertSame(0, $actual);
    }

    public function testSanityCheckFails()
    {
        $properties = [];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Deployer::ERR_INVALID_DEPLOYMENT_SYSTEM)
            ->once();

        $deployer = new Deployer($this->logger, $this->builder);

        $actual = $deployer($properties);
        $this->assertSame(300, $actual);
    }

    public function testMissingRequiredPropertyFails()
    {
        $properties = [
            'script' => [],
            'configuration' => [
                'not-deploy' => [],
            ]
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Deployer::ERR_INVALID_DEPLOYMENT_SYSTEM)
            ->once();

        $deployer = new Deployer($this->logger, $this->builder);

        $actual = $deployer($properties);
        $this->assertSame(300, $actual);
    }

    public function testMissingDeployCommandsFails()
    {
        $properties = [
            'script' => [],
            'configuration' => [
                'deploy' => [],
            ]
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Deployer::ERR_NO_DEPLOY_SCRIPTS)
            ->once();

        $deployer = new Deployer($this->logger, $this->builder);

        $actual = $deployer($properties);
        $this->assertSame(301, $actual);
    }

    public function testDeployCommandsFails()
    {
        $properties = [
            'script' => [],
            'configuration' => [
                'system' => 'unix',
                'pre_push' => ['push1'],
                'deploy' => ['cmd1', 'cmd2'],
                'post_push' => ['push2'],
            ]
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('info', Deployer::SKIP_PRE_PUSH)
            ->once();

        $this->builder
            ->shouldReceive('__invoke')
            ->with(Mockery::type(IO::class), 'unix', ['cmd1', 'cmd2'], $properties)
            ->once()
            ->andReturn(false);

        $deployer = new Deployer($this->logger, $this->builder);
        $deployer->setOutput($this->output);

        $actual = $deployer($properties);
        $this->assertSame(302, $actual);
    }
}
