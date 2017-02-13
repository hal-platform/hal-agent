<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\CodeDeploy;

use Aws\CodeDeploy\CodeDeployClient;
use Aws\S3\S3Client;
use Mockery;
use PHPUnit_Framework_TestCase;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Push\AWSAuthenticator;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
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
        $this->logger = Mockery::mock(EventLogger::CLASS);

        $this->authenticator = Mockery::mock(AWSAuthenticator::CLASS);
        $this->health = Mockery::mock(HealthChecker::CLASS);
        $this->packer = Mockery::mock(Packer::CLASS);
        $this->uploader = Mockery::mock(Uploader::CLASS);
        $this->pusher = Mockery::mock(Pusher::CLASS);
    }

    public function testSuccess()
    {
        $push = $this->buildMockPush();

        $properties = [
            'push' => $push,
            'build' => $push->build(),
            'cd' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'group' => '',
                'configuration' => '',
                'bucket' => '',
                'file' => '',
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
                'tempTarArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $cd = Mockery::mock(CodeDeployClient::CLASS);
        $s3 = Mockery::mock(S3Client::CLASS);
        $this->authenticator
            ->shouldReceive(['getCD' => $cd, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Succeeded']);
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
            $this->authenticator,
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
        $push = $this->buildMockPush();

        $properties = [
            'push' => $push,
            'build' => $push->build(),
            'cd' => [
                'region' => '',
                'credential' => '',
                'application' => 'test_app',
                'group' => 'test_group_id',
                'configuration' => 'rollout_config',
                'bucket' => 'cd_bucket',
                'file' => 'cd_file',
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
                'tempTarArchive' => '/local/build.tar.gz'
            ],
            'environmentVariables' => []
        ];

        $cd = Mockery::mock(CodeDeployClient::CLASS);
        $s3 = Mockery::mock(S3Client::CLASS);
        $this->authenticator
            ->shouldReceive(['getCD' => $cd, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->with($cd, 'test_app', 'test_group_id')
            ->andReturn(['status' => 'Succeeded']);
        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->uploader
            ->shouldReceive('__invoke')
            ->with($s3, '/local/build.tar.gz', 'cd_bucket', 'cd_file', '8956', '1234', 'envname')
            ->andReturn(true);
        $this->pusher
            ->shouldReceive('__invoke')
            ->with($cd, 'test_app', 'test_group_id', 'rollout_config', 'cd_bucket', 'cd_file', '8956', '1234', 'envname')
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
            $this->authenticator,
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
            $this->authenticator,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(500, $actual);
    }

    public function testMissingRequiredPropertyFails()
    {
        $properties = [
            'cd' => [
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
            $this->authenticator,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(500, $actual);
    }

    public function testEnvironmentHealthNotReadyFails()
    {
        $properties = [
            'cd' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'group' => '',
                'configuration' => '',
                'bucket' => '',
                'file' => '',
            ],
            'pushProperties' => [],
            'configuration' => [],
            'location' => [
                'path' => '',
                'tempTarArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $cd = Mockery::mock(CodeDeployClient::CLASS);
        $s3 = Mockery::mock(S3Client::CLASS);
        $this->authenticator
            ->shouldReceive(['getCD' => $cd, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Queued']);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $deployer = new Deployer(
            $this->logger,
            $this->authenticator,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(502, $actual);
    }

    public function testPackerFails()
    {
        $properties = [
            'cd' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'group' => '',
                'configuration' => '',
                'bucket' => '',
                'file' => '',
            ],
            'pushProperties' => [],
            'configuration' => [
                'system' => '',
                'build_transform' => [],
            ],
            'location' => [
                'path' => '',
                'tempTarArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $cd = Mockery::mock(CodeDeployClient::CLASS);
        $s3 = Mockery::mock(S3Client::CLASS);
        $this->authenticator
            ->shouldReceive(['getCD' => $cd, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Stopped']);
        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $deployer = new Deployer(
            $this->logger,
            $this->authenticator,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(503, $actual);
    }

    public function testUploaderFails()
    {
        $push = $this->buildMockPush();

        $properties = [
            'push' => $push,
            'build' => $push->build(),
            'cd' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'group' => '',
                'configuration' => '',
                'bucket' => '',
                'file' => '',
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
                'tempTarArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $cd = Mockery::mock(CodeDeployClient::CLASS);
        $s3 = Mockery::mock(S3Client::CLASS);
        $this->authenticator
            ->shouldReceive(['getCD' => $cd, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Stopped']);
        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->uploader
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $deployer = new Deployer(
            $this->logger,
            $this->authenticator,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(504, $actual);
    }

    public function testPusherFails()
    {
        $push = $this->buildMockPush();

        $properties = [
            'push' => $push,
            'build' => $push->build(),
            'cd' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'group' => '',
                'configuration' => '',
                'bucket' => '',
                'file' => '',
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
                'tempTarArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $cd = Mockery::mock(CodeDeployClient::CLASS);
        $s3 = Mockery::mock(S3Client::CLASS);
        $this->authenticator
            ->shouldReceive(['getCD' => $cd, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'None']);
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
            $this->authenticator,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(505, $actual);
    }

    private function buildMockPush()
    {
        $push = (new Push)
            ->withId('1234')
            ->withBuild(
                (new Build)
                    ->withId('8956')
                    ->withEnvironment(
                        (new Environment)
                            ->withName('envname')
                    )
            )
            ->withApplication(
                (new Application)
                    ->withId('repo_id')
            );

        return $push;
    }
}
