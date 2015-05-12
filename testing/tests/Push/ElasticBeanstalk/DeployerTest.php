<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Repository;
use Symfony\Component\Console\Output\BufferedOutput;

class DeployerTest extends PHPUnit_Framework_TestCase
{
    public $output;
    public $logger;
    public $health;
    public $packer;
    public $uploader;
    public $pusher;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');

        $this->health = Mockery::mock('QL\Hal\Agent\Push\ElasticBeanstalk\HealthChecker');
        $this->packer = Mockery::mock('QL\Hal\Agent\Push\ElasticBeanstalk\Packer');
        $this->uploader = Mockery::mock('QL\Hal\Agent\Push\ElasticBeanstalk\Uploader');
        $this->pusher = Mockery::mock('QL\Hal\Agent\Push\ElasticBeanstalk\Pusher');
    }

    public function testSuccess()
    {
        $repo = new Repository;
        $repo->setId('repo_id');

        $environment = new Environment;
        $environment->setKey('envname');

        $build = new Build;
        $build->setId('8956');
        $build->setEnvironment($environment);

        $push = new Push;
        $push->setId('1234');
        $push->setBuild($build);
        $push->setRepository($repo);

        $properties = [
            'push' => $push,
            'elasticbeanstalk' => [
                'application' => '',
                'environment' => ''
            ],
            'pushProperties' => [],
            'configuration' => [
                'system' => '',
                'build_transform' => ['cmd1'],
                'pre_push' => [],
                'post_push' => [],
                'exclude' => [],
            ],
            'location' => [
                'path' => '',
                'tempZipArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Ready', 'health' => '']);
        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->uploader
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $deployer = new Deployer(
            $this->logger,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(0, $actual);
    }

    public function testPushCommandsAreLoggedAndSkipped()
    {
        $repo = new Repository;
        $repo->setId('repo_id');

        $environment = new Environment;
        $environment->setKey('envname');

        $build = new Build;
        $build->setId('8956');
        $build->setEnvironment($environment);

        $push = new Push;
        $push->setId('1234');
        $push->setBuild($build);
        $push->setRepository($repo);

        $properties = [
            'push' => $push,
            'elasticbeanstalk' => [
                'application' => '',
                'environment' => ''
            ],
            'pushProperties' => [],
            'configuration' => [
                'system' => '',
                'build_transform' => [],
                'pre_push' => ['cmd1'],
                'post_push' => ['cmd2', 'cmd3'],
                'exclude' => [],
            ],
            'location' => [
                'path' => '',
                'tempZipArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Ready', 'health' => '']);
        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->uploader
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->logger
            ->shouldReceive('event')
            ->with('info', Deployer::SKIP_PRE_PUSH)
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->with('info', Deployer::SKIP_POST_PUSH)
            ->once();

        $deployer = new Deployer(
            $this->logger,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

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

        $deployer = new Deployer(
            $this->logger,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(200, $actual);
    }

    public function testEnvironmentHealthNotReadyFails()
    {
        $properties = [
            'elasticbeanstalk' => [
                'application' => '',
                'environment' => ''
            ],
            'pushProperties' => [],
            'configuration' => [],
            'location' => [
                'path' => '',
                'tempZipArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Terminated', 'health' => 'Grey']);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $deployer = new Deployer(
            $this->logger,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(201, $actual);
    }

    public function testPackerFails()
    {
        $properties = [
            'elasticbeanstalk' => [
                'application' => '',
                'environment' => ''
            ],
            'pushProperties' => [],
            'configuration' => [
                'system' => '',
                'build_transform' => [],
            ],
            'location' => [
                'path' => '',
                'tempZipArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Ready', 'health' => 'Grey']);
        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $deployer = new Deployer(
            $this->logger,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(202, $actual);
    }

    public function testUploaderFails()
    {
        $repo = new Repository;
        $repo->setId('repo_id');

        $environment = new Environment;
        $environment->setKey('envname');

        $build = new Build;
        $build->setId('8956');
        $build->setEnvironment($environment);

        $push = new Push;
        $push->setId('1234');
        $push->setBuild($build);
        $push->setRepository($repo);

        $properties = [
            'push' => $push,
            'elasticbeanstalk' => [
                'application' => '',
                'environment' => ''
            ],
            'pushProperties' => [],
            'configuration' => [
                'system' => '',
                'build_transform' => [],
                'pre_push' => [],
                'post_push' => [],
            ],
            'location' => [
                'path' => '',
                'tempZipArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Ready', 'health' => 'Grey']);
        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->uploader
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $deployer = new Deployer(
            $this->logger,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(203, $actual);
    }

    public function testPusherFails()
    {
        $repo = new Repository;
        $repo->setId('repo_id');

        $environment = new Environment;
        $environment->setKey('envname');

        $build = new Build;
        $build->setId('8956');
        $build->setEnvironment($environment);

        $push = new Push;
        $push->setId('1234');
        $push->setBuild($build);
        $push->setRepository($repo);

        $properties = [
            'push' => $push,
            'elasticbeanstalk' => [
                'application' => '',
                'environment' => ''
            ],
            'pushProperties' => [],
            'configuration' => [
                'system' => '',
                'build_transform' => [],
                'pre_push' => [],
                'post_push' => [],
            ],
            'location' => [
                'path' => '',
                'tempZipArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Ready', 'health' => 'Grey']);
        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->uploader
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $deployer = new Deployer(
            $this->logger,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(204, $actual);
    }
}
