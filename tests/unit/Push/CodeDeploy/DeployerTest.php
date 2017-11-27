<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\CodeDeploy;

use Aws\CodeDeploy\CodeDeployClient;
use Aws\S3\S3Client;
use Hal\Core\AWS\AWSAuthenticator;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Push\ReleasePacker;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\Release;
use Hal\Core\Entity\Target;
use Hal\Core\Entity\Group;
use Symfony\Component\Console\Output\BufferedOutput;

class DeployerTest extends MockeryTestCase
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
        $this->logger = Mockery::mock(EventLogger::class);

        $this->authenticator = Mockery::mock(AWSAuthenticator::class);
        $this->health = Mockery::mock(HealthChecker::class);
        $this->packer = Mockery::mock(ReleasePacker::class);
        $this->uploader = Mockery::mock(Uploader::class);
        $this->pusher = Mockery::mock(Pusher::class);
    }

    public function testSuccess()
    {
        $release = $this->buildMockRelease();

        $properties = [
            'release' => $release,
            'build' => $release->build(),
            'cd' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'group' => '',
                'configuration' => '',
                'bucket' => '',
                'file' => '',
                'src' => ''
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
                'tempUploadArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $cd = Mockery::mock(CodeDeployClient::class);
        $s3 = Mockery::mock(S3Client::class);
        $this->authenticator
            ->shouldReceive(['getCD' => $cd, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Succeeded']);
        $this->packer
            ->shouldReceive('packZipOrTar')
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
        $release = $this->buildMockRelease();

        $properties = [
            'release' => $release,
            'build' => $release->build(),
            'cd' => [
                'region' => '',
                'credential' => '',
                'application' => 'test_app',
                'group' => 'test_group_id',
                'configuration' => 'rollout_config',
                'bucket' => 'cd_bucket',
                'file' => 'cd_file',
                'src' => '.',
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
                'tempUploadArchive' => '/local/build.tar.gz'
            ],
            'environmentVariables' => []
        ];

        $cd = Mockery::mock(CodeDeployClient::class);
        $s3 = Mockery::mock(S3Client::class);
        $this->authenticator
            ->shouldReceive(['getCD' => $cd, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->with($cd, 'test_app', 'test_group_id')
            ->andReturn(['status' => 'Succeeded']);
        $this->packer
            ->shouldReceive('packZipOrTar')
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
                'src' => '',
            ],
            'pushProperties' => [],
            'configuration' => [],
            'location' => [
                'path' => '',
                'tempUploadArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $cd = Mockery::mock(CodeDeployClient::class);
        $s3 = Mockery::mock(S3Client::class);
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
                'src' => '',
            ],
            'pushProperties' => [],
            'configuration' => [
                'system' => '',
                'build_transform' => [],
            ],
            'location' => [
                'path' => '',
                'tempUploadArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $cd = Mockery::mock(CodeDeployClient::class);
        $s3 = Mockery::mock(S3Client::class);
        $this->authenticator
            ->shouldReceive(['getCD' => $cd, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Stopped']);
        $this->packer
            ->shouldReceive('packZipOrTar')
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
        $release = $this->buildMockRelease();

        $properties = [
            'release' => $release,
            'build' => $release->build(),
            'cd' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'group' => '',
                'configuration' => '',
                'bucket' => '',
                'file' => '',
                'src' => '',
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
                'tempUploadArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $cd = Mockery::mock(CodeDeployClient::class);
        $s3 = Mockery::mock(S3Client::class);
        $this->authenticator
            ->shouldReceive(['getCD' => $cd, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Stopped']);
        $this->packer
            ->shouldReceive('packZipOrTar')
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
        $release = $this->buildMockRelease();

        $properties = [
            'release' => $release,
            'build' => $release->build(),
            'cd' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'group' => '',
                'configuration' => '',
                'bucket' => '',
                'file' => '',
                'src' => '',
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
                'tempUploadArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $cd = Mockery::mock(CodeDeployClient::class);
        $s3 = Mockery::mock(S3Client::class);
        $this->authenticator
            ->shouldReceive(['getCD' => $cd, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'None']);
        $this->packer
            ->shouldReceive('packZipOrTar')
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

    private function buildMockRelease()
    {
        $env = (new Environment)->withName('envname');

        $push = (new Release())
            ->withId('1234')
            ->withBuild(
                (new Build)
                    ->withId('8956')
                    ->withEnvironment($env)
            )
            ->withApplication(
                (new Application)
                    ->withId('repo_id')
            )
            ->withTarget(
                (new Target)
                    ->withGroup(
                        (new Group)
                            ->withEnvironment($env)
                    )
            );

        return $push;
    }
}
