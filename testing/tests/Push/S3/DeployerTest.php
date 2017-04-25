<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Push\AWSAuthenticator;
use Mockery;
use PHPUnit_Framework_TestCase;
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
    public $preparer;
    public $uploader;
    public $pusher;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock(EventLogger::class);

        $this->authenticator = Mockery::mock(AWSAuthenticator::class);
        $this->preparer = Mockery::mock(Preparer::class);
        $this->uploader = Mockery::mock(Uploader::class);
    }

    public function testSuccess()
    {
        $properties = [
            'push' => $this->buildMockPush(),
            's3' => [
                'region' => 'aws-region',
                'credential' => 'aws-cred',
                'bucket' => 'eb_bucket',
                'file' => 'eb_file',
                'src' => 's3_file',
            ],
            'configuration' => [
                'pre_push' => [],
                'post_push' => []
            ],
            'location' => [
                'path' => '/temp/build',
                'tempUploadArchive' => 'file.tar.gz'
            ]
        ];

        $s3 = Mockery::mock('Aws\S3\S3Client');
        $this->authenticator
            ->shouldReceive('getS3')
            ->with('aws-region', 'aws-cred')
            ->andReturn($s3);

        $this->preparer
            ->shouldReceive('__invoke')
            ->with('/temp/build', 's3_file', 'file.tar.gz', 'eb_file')
            ->andReturn(true);

        $this->uploader
            ->shouldReceive('__invoke')
            ->with(
                $s3,
                'file.tar.gz',
                'eb_bucket',
                'eb_file',
                '8956',
                '1234',
                'envname'
            )
            ->andReturn(true);

        $deployer = new Deployer(
            $this->logger,
            $this->authenticator,
            $this->preparer,
            $this->uploader
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
            $this->preparer,
            $this->uploader
        );

        $actual = $deployer($properties);
        $this->assertSame(400, $actual);
    }

    public function testMissingRequiredPropertyFails()
    {
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
            $this->authenticator,
            $this->preparer,
            $this->uploader
        );

        $actual = $deployer($properties);
        $this->assertSame(400, $actual);
    }

    public function testAuthenticatorFails()
    {
        $properties = [
            's3' => [
                'region' => 'aws-region',
                'credential' => 'aws-cred',
                'bucket' => 'eb_bucket',
                'file' => 'eb_file',
                'src' => '.'
            ]
        ];

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturnNull();

        $deployer = new Deployer(
            $this->logger,
            $this->authenticator,
            $this->preparer,
            $this->uploader
        );

        $actual = $deployer($properties);
        $this->assertSame(401, $actual);
    }

    public function testPreparerFails()
    {
        $properties = [
            'push' => $this->buildMockPush(),
            's3' => [
                'region' => 'aws-region',
                'credential' => 'aws-cred',
                'bucket' => 'eb_bucket',
                'file' => 'eb_file',
                'src' => '.'
            ],
            'location' => [
                'path' => '/temp/build',
                'tempUploadArchive' => 'file.tar.gz'
            ]
        ];

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn(Mockery::mock('Aws\S3\S3Client'));

        $this->preparer
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $deployer = new Deployer(
            $this->logger,
            $this->authenticator,
            $this->preparer,
            $this->uploader
        );

        $actual = $deployer($properties);
        $this->assertSame(402, $actual);
    }

    public function testUploaderFails()
    {
        $properties = [
            'push' => $this->buildMockPush(),
            's3' => [
                'region' => 'aws-region',
                'credential' => 'aws-cred',
                'bucket' => 'eb_bucket',
                'file' => 'eb_file',
                'src' => '.'
            ],
            'configuration' => [
                'pre_push' => [],
                'post_push' => []
            ],
            'location' => [
                'path' => '/temp/build',
                'tempUploadArchive' => 'file.tar.gz'
            ]
        ];

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn(Mockery::mock('Aws\S3\S3Client'));

        $this->preparer
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->uploader
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $deployer = new Deployer(
            $this->logger,
            $this->authenticator,
            $this->preparer,
            $this->uploader
        );

        $actual = $deployer($properties);
        $this->assertSame(403, $actual);
    }

    public function testPushCommandsAreLoggedAndSkipped()
    {
        $properties = [
            'push' => $this->buildMockPush(),
            's3' => [
                'region' => 'aws-region',
                'credential' => 'aws-cred',
                'bucket' => 'eb_bucket',
                'file' => 'eb_file',
                'src' => '.'
            ],
            'configuration' => [
                'pre_push' => ['derp'],
                'post_push' => ['doop']
            ],
            'location' => [
                'path' => '/temp/build',
                'tempUploadArchive' => 'file.tar.gz'
            ]
        ];

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn(Mockery::mock('Aws\S3\S3Client'));

        $this->preparer
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->uploader
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
            $this->authenticator,
            $this->preparer,
            $this->uploader
        );

        $actual = $deployer($properties);
        $this->assertSame(0, $actual);
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
