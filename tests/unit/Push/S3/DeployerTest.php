<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3;

use Hal\Agent\Logger\EventLogger;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\Release;
use Hal\Core\Entity\Target;
use Hal\Core\Entity\Group;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Hal\Agent\Push\S3\Sync\Deployer as SyncDeployer;
use Hal\Agent\Push\S3\Artifact\Deployer as ArtifactDeployer;

class DeployerTest extends MockeryTestCase
{
    public $output;
    public $logger;
    public $health;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock(EventLogger::class);

        $this->container = Mockery::mock(ContainerInterface::class);
        $this->artifactDeployer = Mockery::mock(ArtifactDeployer::class);
        $this->syncDeployer = Mockery::mock(SyncDeployer::class);

        $this->strategies = [
            'artifact' => 'push.s3_artifact.deployer',
            'sync' => 'push.s3_sync.deployer'
        ];
    }

    public function testSanityCheckFails()
    {
        $this->markTestSkipped();

        $properties = [];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Deployer::ERR_INVALID_DEPLOYMENT_SYSTEM)
            ->once();

        $deployer = new Deployer(
            $this->logger,
            $this->container,
            $this->strategies
        );

        $actual = $deployer($properties);
        $this->assertSame(400, $actual);
    }

    public function testMissingRequiredPropertyFails()
    {
        $this->markTestSkipped();

        $properties = [
            's3' => [
                'region' => '',
                'credential' => ''
            ]
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Deployer::ERR_INVALID_DEPLOYMENT_SYSTEM)
            ->once();

        $deployer = new Deployer(
            $this->logger,
            $this->container,
            $this->strategies
        );

        $actual = $deployer($properties);
        $this->assertSame(400, $actual);
    }

    public function testArtifactStrategy()
    {
        $this->markTestSkipped();

        $properties = [
            's3' => [
                'region' => 'useast-1',
                'credential' => '.',
                'bucket' => 'bucket',
                'file' => 'file',
                'src' => 'src',
                'path' => 'path',
                'strategy' => 'artifact'
            ]
        ];


        $this->container
            ->shouldReceive('get')
            ->with('push.s3_artifact.deployer', ContainerInterface::NULL_ON_INVALID_REFERENCE)
            ->andReturn($this->artifactDeployer);

        $this->artifactDeployer
            ->shouldReceive('setOutput')
            ->with($this->output);

        $this->artifactDeployer
            ->shouldReceive('__invoke')
            ->with($properties)
            ->andReturn(0);

        $deployer = new Deployer(
            $this->logger,
            $this->container,
            $this->strategies
        );

        $deployer->setOutput($this->output);

        $actual = $deployer($properties);
        $this->assertSame(0, $actual);
    }

    public function testSyncStrategy()
    {
        $this->markTestSkipped();

        $properties = [
            's3' => [
                'region' => 'useast-1',
                'credential' => '.',
                'bucket' => 'bucket',
                'file' => 'file',
                'src' => 'src',
                'path' => 'path',
                'strategy' => 'sync'
            ]
        ];

        $this->container
            ->shouldReceive('get')
            ->with('push.s3_sync.deployer', ContainerInterface::NULL_ON_INVALID_REFERENCE)
            ->andReturn($this->syncDeployer);

        $this->syncDeployer
            ->shouldReceive('setOutput')
            ->with($this->output);

        $this->syncDeployer
            ->shouldReceive('__invoke')
            ->with($properties)
            ->andReturn(0);

        $deployer = new Deployer(
            $this->logger,
            $this->container,
            $this->strategies
        );

        $deployer->setOutput($this->output);

        $actual = $deployer($properties);
        $this->assertSame(0, $actual);
    }

    public function testInvalidStrategy()
    {
        $this->markTestSkipped();

        $properties = [
            's3' => [
                'region' => 'useast-1',
                'credential' => '.',
                'bucket' => 'bucket',
                'file' => 'file',
                'src' => 'src',
                'path' => 'path',
                'strategy' => 'invalid'
            ]
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Deployer::ERR_UNABLE_TO_DETERMINE_STRATEGY)
            ->once();

        $deployer = new Deployer(
            $this->logger,
            $this->container,
            $this->strategies
        );

        $actual = $deployer($properties);
        $this->assertSame(404, $actual);
    }
}
