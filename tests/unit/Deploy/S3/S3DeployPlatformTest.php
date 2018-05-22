<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3;

use AWS\S3\S3Client;
use Hal\Agent\AWS\S3Uploader;
use Hal\Agent\Deploy\S3\Steps\Configurator;
use Hal\Agent\Deploy\S3\Steps\Compressor;
use Hal\Agent\Deploy\S3\Steps\SyncUploader;
use Hal\Agent\JobExecution;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\IOTestCase;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Release;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class S3DeployPlatformTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $configurator;
    public $compressor;
    public $s3Uploader;
    public $syncUploader;

    public $s3;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->configurator = Mockery::mock(Configurator::class);
        $this->compressor = Mockery::mock(Compressor::class);
        $this->s3Uploader = Mockery::mock(S3Uploader::class);
        $this->syncUploader = Mockery::mock(SyncUploader::class);

        $this->s3 = Mockery::Mock(S3Client::class);
    }

    public function testWrongJobTypeFails()
    {
        $job = new Job;
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'The provided job is an invalid type for this job platform')
            ->once();

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->compressor,
            $this->s3Uploader,
            $this->syncUploader
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] The provided job is an invalid type for this job platform'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testMissingConfigFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn([]);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'S3 deploy platform is not configured correctly')
            ->once();

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->compressor,
            $this->s3Uploader,
            $this->syncUploader
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] S3 deploy platform is not configured correctly'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testSuccessForArtifactUpload()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/tmp/1234'
        ];

        $config =[
            'sdk' => [
                's3' => $this->s3
            ],
            'region' => 'us-test-1',
            'bucket' => 'target_bucket',
            'method' => 'artifact',

            'local_path' => 'source_file',
            'remote_path' => 'target_file'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->compressor
            ->shouldReceive('__invoke')
            ->with('/tmp/1234/workspace/source_file', '/tmp/1234/build_export.compressed', 'target_file')
            ->andReturn(true);

        $this->s3Uploader
            ->shouldReceive('__invoke')
            ->with(
                $this->s3,
                '/tmp/1234/build_export.compressed',
                'target_bucket',
                'target_file',
                [
                    'Job' => '1234',
                    'Environment' => 'UnitTestEnv'
                ]
            )
            ->andReturn(true);

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->compressor,
            $this->s3Uploader,
            $this->syncUploader
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = <<<'OUTPUT_TEXT'
S3 Platform - Validating S3 configuration

Platform configuration:
  sdk             {
                      "s3": {}
                  }
  region          "us-test-1"
  bucket          "target_bucket"
  method          "artifact"
  local_path      "source_file"
  remote_path     "target_file"

S3 Platform - Compressing source
 * Local Path: /tmp/1234/workspace/source_file
 * Temp Artifact: /tmp/1234/build_export.compressed

S3 Platform - Uploading artifacts to S3 bucket
OUTPUT_TEXT;

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(true, $actual);
    }

    public function testSuccessForSyncUpload()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/tmp/1234'
        ];

        $config =[
            'sdk' => [
                's3' => $this->s3
            ],
            'region' => 'us-test-1',
            'bucket' => 'target_bucket',
            'method' => 'sync',

            'local_path' => 'source_path',
            'remote_path' => 'target_path'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->compressor
            ->shouldReceive('__invoke')
            ->with('/tmp/1234/workspace/source_file', '/tmp/1234/build_export.compressed', 'target_file')
            ->andReturn(true);

        $this->syncUploader
            ->shouldReceive('__invoke')
            ->with(
                $this->s3,
                '/tmp/1234/workspace/source_path',
                'target_bucket',
                'target_path'
            )
            ->andReturn(true);

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->compressor,
            $this->s3Uploader,
            $this->syncUploader
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = <<<'OUTPUT_TEXT'
S3 Platform - Validating S3 configuration

Platform configuration:
  sdk             {
                      "s3": {}
                  }
  region          "us-test-1"
  bucket          "target_bucket"
  method          "sync"
  local_path      "source_path"
  remote_path     "target_path"

S3 Platform - Compressing source
 ! [NOTE] Skipping compression step in sync mode

S3 Platform - Uploading artifacts to S3 bucket
OUTPUT_TEXT;

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(true, $actual);
    }

    public function generateMockExecution()
    {
        return new JobExecution('s3', 'deploy', []);
    }

    public function generateMockRelease()
    {
        return (new Release('1234'))
            ->withEnvironment(
                (new Environment('1234'))
                    ->withName('UnitTestEnv')
            );
    }
}
