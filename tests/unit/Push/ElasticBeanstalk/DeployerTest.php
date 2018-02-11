<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\ElasticBeanstalk;

use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Aws\S3\S3Client;
use Hal\Core\AWS\AWSAuthenticator;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Push\ReleasePacker;
use Hal\Agent\Push\ElasticBeanstalk\HealthChecker;
use Hal\Agent\Push\ElasticBeanstalk\Pusher;
use Hal\Agent\Push\ElasticBeanstalk\Uploader;
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
        $this->markTestSkipped();

        $push = $this->buildMockRelease();

        $properties = [
            'release' => $push,
            'build' => $push->build(),
            'eb' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'environment' => '',
                'bucket' => '',
                'file' => '',
                'src' => '',
            ],
            'pushProperties' => [],
            'configuration' => [
                'platform' => '',
                'image' => '',
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

        $eb = Mockery::mock(ElasticBeanstalkClient::class);
        $s3 = Mockery::mock(S3Client::class);
        $this->authenticator
            ->shouldReceive(['getEB' => $eb, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Ready', 'health' => '']);
        $this->packer
            ->shouldReceive('packZip')
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
        $this->markTestSkipped();

        $release = $this->buildMockRelease();

        $properties = [
            'release' => $release,
            'build' => $release->build(),
            'eb' => [
                'region' => '',
                'credential' => '',
                'application' => 'test_app',
                'environment' => 'test_env_id',
                'bucket' => 'eb_bucket',
                'file' => 'eb_file',
                'src' => 's3_file',
            ],
            'pushProperties' => [],
            'configuration' => [
                'platform' => '',
                'image' => '',
                'build_transform' => [],
                'pre_push' => ['cmd1'],
                'post_push' => ['cmd2', 'cmd3'],
                'exclude' => [],
            ],
            'location' => [
                'path' => '',
                'tempUploadArchive' => '/local/build.zip'
            ],
            'environmentVariables' => []
        ];

        $eb = Mockery::mock(ElasticBeanstalkClient::class);
        $s3 = Mockery::mock(S3Client::class);
        $this->authenticator
            ->shouldReceive(['getEB' => $eb, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->with($eb, 'test_app', 'test_env_id')
            ->andReturn(['status' => 'Ready', 'health' => '']);
        $this->packer
            ->shouldReceive('packZip')
            ->andReturn(true);
        $this->uploader
            ->shouldReceive('__invoke')
            ->with($s3, '/local/build.zip', 'eb_bucket', 'eb_file', ['Build' => '8956', 'Release' => '1234', 'Environment' => 'envname'])
            ->andReturn(true);
        $this->pusher
            ->shouldReceive('__invoke')
            ->with($eb, 'test_app', 'test_env_id', 'eb_bucket', 'eb_file', '8956', '1234', 'envname')
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
        $this->markTestSkipped();

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
        $this->assertSame(200, $actual);
    }

    public function testMissingRequiredPropertyFails()
    {
        $this->markTestSkipped();

        $properties = [
            'eb' => [
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
        $this->assertSame(200, $actual);
    }

    public function testEnvironmentHealthNotReadyFails()
    {
        $this->markTestSkipped();

        $properties = [
            'eb' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'environment' => '',
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

        $eb = Mockery::mock(ElasticBeanstalkClient::class);
        $s3 = Mockery::mock(S3Client::class);
        $this->authenticator
            ->shouldReceive(['getEB' => $eb, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Terminated', 'health' => 'Grey']);

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
        $this->assertSame(202, $actual);
    }

    public function testPackerFails()
    {
        $this->markTestSkipped();

        $properties = [
            'eb' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'environment' => '',
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

        $eb = Mockery::mock(ElasticBeanstalkClient::class);
        $s3 = Mockery::mock(S3Client::class);
        $this->authenticator
            ->shouldReceive(['getEB' => $eb, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Ready', 'health' => 'Grey']);
        $this->packer
            ->shouldReceive('packZip')
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
        $this->assertSame(203, $actual);
    }

    public function testUploaderFails()
    {
        $this->markTestSkipped();

        $release = $this->buildMockRelease();

        $properties = [
            'release' => $release,
            'build' => $release->build(),
            'eb' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'environment' => '',
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

        $eb = Mockery::mock(ElasticBeanstalkClient::class);
        $s3 = Mockery::mock(S3Client::class);
        $this->authenticator
            ->shouldReceive(['getEB' => $eb, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Ready', 'health' => 'Grey']);
        $this->packer
            ->shouldReceive('packZip')
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
        $this->assertSame(204, $actual);
    }

    public function testPusherFails()
    {
        $this->markTestSkipped();

        $release = $this->buildMockRelease();

        $properties = [
            'release' => $release,
            'build' => $release->build(),
            'eb' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'environment' => '',
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

        $eb = Mockery::mock(ElasticBeanstalkClient::class);
        $s3 = Mockery::mock(S3Client::class);
        $this->authenticator
            ->shouldReceive(['getEB' => $eb, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Ready', 'health' => 'Grey']);
        $this->packer
            ->shouldReceive('packZip')
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
        $this->assertSame(205, $actual);
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
