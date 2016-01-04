<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\S3;

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
    public $packer;
    public $uploader;
    public $pusher;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');

        $this->authenticator = Mockery::mock('QL\Hal\Agent\Push\AWSAuthenticator');
        $this->packer = Mockery::mock('QL\Hal\Agent\Push\S3\Packer');
        $this->uploader = Mockery::mock('QL\Hal\Agent\Push\S3\Uploader');
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
            ],
            'configuration' => [
                'pre_push' => [],
                'post_push' => []
            ],
            'location' => [
                'path' => '/temp/build',
                'tempTarArchive' => 'file.tar.gz'
            ]
        ];

        $s3 = Mockery::mock('Aws\S3\S3Client');
        $this->authenticator
            ->shouldReceive('getS3')
            ->with('aws-region', 'aws-cred')
            ->andReturn($s3);

        $this->packer
            ->shouldReceive('__invoke')
            ->with('/temp/build', '.', 'file.tar.gz')
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
            $this->packer,
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
            $this->packer,
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
            $this->packer,
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
            ]
        ];

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturnNull();

        $deployer = new Deployer(
            $this->logger,
            $this->authenticator,
            $this->packer,
            $this->uploader
        );

        $actual = $deployer($properties);
        $this->assertSame(401, $actual);
    }

    public function testPackerFails()
    {
        $properties = [
            'push' => $this->buildMockPush(),
            's3' => [
                'region' => 'aws-region',
                'credential' => 'aws-cred',
                'bucket' => 'eb_bucket',
                'file' => 'eb_file',
            ],
            'location' => [
                'path' => '/temp/build',
                'tempTarArchive' => 'file.tar.gz'
            ]
        ];

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn(Mockery::mock('Aws\S3\S3Client'));

        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $deployer = new Deployer(
            $this->logger,
            $this->authenticator,
            $this->packer,
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
            ],
            'configuration' => [
                'pre_push' => [],
                'post_push' => []
            ],
            'location' => [
                'path' => '/temp/build',
                'tempTarArchive' => 'file.tar.gz'
            ]
        ];

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn(Mockery::mock('Aws\S3\S3Client'));

        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->uploader
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $deployer = new Deployer(
            $this->logger,
            $this->authenticator,
            $this->packer,
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
            ],
            'configuration' => [
                'pre_push' => ['derp'],
                'post_push' => ['doop']
            ],
            'location' => [
                'path' => '/temp/build',
                'tempTarArchive' => 'file.tar.gz'
            ]
        ];

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn(Mockery::mock('Aws\S3\S3Client'));

        $this->packer
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
            $this->packer,
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
