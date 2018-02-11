<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3\Sync;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Push\S3\Sync\Preparer;
use Hal\Agent\Push\S3\Sync\Uploader;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\Release;
use Symfony\Component\Console\Output\BufferedOutput;

class DeployerTest extends MockeryTestCase
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

    public function testAuthenticatorFails()
    {
        $this->markTestSkipped();

        $properties = [
            's3' => [
                'region' => 'aws-region',
                'credential' => 'aws-cred',
                'strategy' => 'artifact',
                'bucket' => 'eb_bucket',
                'file' => 'eb_file',
                'src' => '.',
                'path' => '/temp/build-1234'
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

    public function testSuccess()
    {
        $this->markTestSkipped();

        $properties = [
            'release' => $this->buildMockRelease(),
            's3' => [
                'region' => 'aws-region',
                'credential' => 'aws-cred',
                'strategy' => 'sync',
                'bucket' => 'eb_bucket',
                'file' => 'eb_file',
                'src' => '.',
                'path' => '/temp/build/.'
            ],
            'configuration' => [],
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
            ->with('/temp/build', '.')
            ->andReturn(true);

        $this->uploader
            ->shouldReceive('__invoke')
            ->with(
                $s3,
                '/temp/build/.',
                'eb_bucket',
                'eb_file',
                [
                    'Build' => '8956',
                    'Push' => '1234',
                    'Environment' => 'envname'
                ]
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

    public function testPreparerFails()
    {
        $this->markTestSkipped();

        $properties = [
            'release' => $this->buildMockRelease(),
            's3' => [
                'region' => 'aws-region',
                'credential' => 'aws-cred',
                'strategy' => 'artifact',
                'bucket' => 'eb_bucket',
                'file' => 'eb_file',
                'src' => '.',
                'path' => '/temp/build/.'
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
        $this->markTestSkipped();

        $properties = [
            'release' => $this->buildMockRelease(),
            's3' => [
                'region' => 'aws-region',
                'credential' => 'aws-cred',
                'strategy' => 'artifact',
                'bucket' => 'eb_bucket',
                'file' => 'eb_file',
                'src' => '.',
                'path' => '/temp/build/.'
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

    private function buildMockRelease()
    {
        $release = (new Release)
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

        return $release;
    }

}
