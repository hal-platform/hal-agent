<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Aws\S3\S3Client;
use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Push\AWSAuthenticator;
use QL\Hal\Agent\Push\ElasticBeanstalk\HealthChecker;
use QL\Hal\Agent\Push\ElasticBeanstalk\Packer;
use QL\Hal\Agent\Push\ElasticBeanstalk\Pusher;
use QL\Hal\Agent\Push\ElasticBeanstalk\Uploader;
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
            'eb' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'environment' => '',
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
                'tempZipArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $eb = Mockery::mock(ElasticBeanstalkClient::CLASS);
        $s3 = Mockery::mock(S3Client::CLASS);
        $this->authenticator
            ->shouldReceive(['getEB' => $eb, 'getS3' => $s3]);

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
            'eb' => [
                'region' => '',
                'credential' => '',
                'application' => 'test_app',
                'environment' => 'test_env_id',
                'bucket' => 'eb_bucket',
                'file' => 'eb_file',
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
                'tempZipArchive' => '/local/build.zip'
            ],
            'environmentVariables' => []
        ];

        $eb = Mockery::mock(ElasticBeanstalkClient::CLASS);
        $s3 = Mockery::mock(S3Client::CLASS);
        $this->authenticator
            ->shouldReceive(['getEB' => $eb, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->with($eb, 'test_app', 'test_env_id')
            ->andReturn(['status' => 'Ready', 'health' => '']);
        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->uploader
            ->shouldReceive('__invoke')
            ->with($s3, '/local/build.zip', 'eb_bucket', 'eb_file', '8956', '1234', 'envname')
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
        $properties = [
            'eb' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'environment' => '',
                'bucket' => '',
                'file' => '',
            ],
            'pushProperties' => [],
            'configuration' => [],
            'location' => [
                'path' => '',
                'tempZipArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $eb = Mockery::mock(ElasticBeanstalkClient::CLASS);
        $s3 = Mockery::mock(S3Client::CLASS);
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
        $properties = [
            'eb' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'environment' => '',
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
                'tempZipArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $eb = Mockery::mock(ElasticBeanstalkClient::CLASS);
        $s3 = Mockery::mock(S3Client::CLASS);
        $this->authenticator
            ->shouldReceive(['getEB' => $eb, 'getS3' => $s3]);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn(['status' => 'Ready', 'health' => 'Grey']);
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
        $this->assertSame(203, $actual);
    }

    public function testUploaderFails()
    {
        $push = $this->buildMockPush();

        $properties = [
            'push' => $push,
            'build' => $push->build(),
            'eb' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'environment' => '',
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
                'tempZipArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $eb = Mockery::mock(ElasticBeanstalkClient::CLASS);
        $s3 = Mockery::mock(S3Client::CLASS);
        $this->authenticator
            ->shouldReceive(['getEB' => $eb, 'getS3' => $s3]);

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
        $push = $this->buildMockPush();

        $properties = [
            'push' => $push,
            'build' => $push->build(),
            'eb' => [
                'region' => '',
                'credential' => '',
                'application' => '',
                'environment' => '',
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
                'tempZipArchive' => ''
            ],
            'environmentVariables' => []
        ];

        $eb = Mockery::mock(ElasticBeanstalkClient::CLASS);
        $s3 = Mockery::mock(S3Client::CLASS);
        $this->authenticator
            ->shouldReceive(['getEB' => $eb, 'getS3' => $s3]);

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
            $this->authenticator,
            $this->health,
            $this->packer,
            $this->uploader,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(205, $actual);
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
