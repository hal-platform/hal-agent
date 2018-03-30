<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticBeanstalk;

use AWS\S3\S3Client;
use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Hal\Agent\AWS\S3Uploader;
use Hal\Agent\Deploy\ElasticBeanstalk\Steps\DeploymentVerifier;
use Hal\Agent\Deploy\ElasticBeanstalk\Steps\Configurator;
use Hal\Agent\Deploy\ElasticBeanstalk\Steps\HealthChecker;
use Hal\Agent\Deploy\ElasticBeanstalk\Steps\Deployer;
use Hal\Agent\Deploy\ElasticBeanstalk\Steps\Compressor;
use Hal\Agent\JobExecution;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\IOTestCase;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\JobType\Build;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class EBDeployPlatformTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $configurator;
    public $compressor;
    public $artifactUploader;
    public $verifier;
    public $health;
    public $deployer;

    public $eb;
    public $s3;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->configurator = Mockery::mock(Configurator::class);
        $this->compressor = Mockery::mock(Compressor::class);
        $this->artifactUploader = Mockery::mock(S3Uploader::class);
        $this->verifier = Mockery::mock(DeploymentVerifier::class);
        $this->health = Mockery::mock(HealthChecker::class);
        $this->deployer = Mockery::mock(Deployer::class);

        $this->eb = Mockery::mock(ElasticBeanstalkClient::class);
        $this->s3 = Mockery::Mock(S3Client::class);

    }

    public function testWrongJobTypeFails()
    {
        $job = new Job;
        $execution = $this->generateMockExecution();
        $properties = [];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'The provided job is an invalid type for this job platform', Mockery::any())
            ->once();

        $platform = new EBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->compressor,
            $this->artifactUploader,
            $this->health,
            $this->deployer,
            $this->verifier
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
        $properties = [];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn([]);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'ElasticBeanstalk deploy platform is not configured correctly', Mockery::any())
            ->once();

        $platform = new EBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->compressor,
            $this->artifactUploader,
            $this->health,
            $this->deployer,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] ElasticBeanstalk deploy platform is not configured correctly'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testEBPlatformIsNotReady()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [];

        $config = $this->configuration();

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn([
                'status' => 'Updating',
                'health' => 'Grey'
            ]);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Elastic Beanstalk environment is not ready', Mockery::any())
            ->once();

        $platform = new EBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->compressor,
            $this->artifactUploader,
            $this->health,
            $this->deployer,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] Elastic Beanstalk environment is not ready'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testCompressorError()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $config = $this->configuration();

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn([
                'status' => 'Ready',
                'health' => 'Green'
            ]);

        $this->compressor
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'The source directory could not be compressed', Mockery::any())
            ->once();

        $platform = new EBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->compressor,
            $this->artifactUploader,
            $this->health,
            $this->deployer,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] The source directory could not be compressed'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testUploaderError()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $config = $this->configuration();

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn([
                'status' => 'Ready',
                'health' => 'Green'
            ]);

        $this->compressor
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->artifactUploader
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'The artifact(s) could not be uploaded to the S3 Bucket', Mockery::any())
            ->once();

        $platform = new EBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->compressor,
            $this->artifactUploader,
            $this->health,
            $this->deployer,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] The artifact(s) could not be uploaded to the S3 Bucket'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testPusherError()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $config = $this->configuration();

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn([
                'status' => 'Ready',
                'health' => 'Green'
            ]);

        $this->compressor
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->artifactUploader
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->deployer
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'The artifact(s) could not be pushed to the ElasticBeanstalk', Mockery::any())
            ->once();

        $platform = new EBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->compressor,
            $this->artifactUploader,
            $this->health,
            $this->deployer,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] The artifact(s) could not be pushed to the ElasticBeanstalk'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testSuccessForArtifactUpload()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $config = $this->configuration();

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->compressor
            ->shouldReceive('__invoke')
            ->with('/workspace/job/.', '/workspace/build_export.compressed', 'file.zip')
            ->andReturn(true);

        $this->health
            ->shouldReceive('__invoke')
            ->with($this->eb, 'application', 'environment')
            ->andReturn([
                'status' => 'Ready',
                'health' => 'Green'
            ])
            ->once();

        $this->artifactUploader
            ->shouldReceive('__invoke')
            ->with(
                $this->s3,
                '/workspace/build_export.compressed',
                'bucket',
                'file.zip',
                [
                    'Job' => '1234',
                    'Environment' => 'UnitTestEnv'
                ]
            )
            ->andReturn(true);

        $this->deployer
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->verifier
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $platform = new EBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->compressor,
            $this->artifactUploader,
            $this->health,
            $this->deployer,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            'EB Platform - Validating EB configuration',
            'Platform configuration:',
            '  sdk                      {',
            '                               "s3": {}',
            '                               "eb": {}',
            '                           }',
            '  region                   "us-test-1"',
            '  application              "application"',
            '  environment              "environment"',
            '  bucket                   "bucket"',
            '  method                   "artifact"',
            '  local_path               "."',
            '  remote_path              "file.zip"',
            '  deployment_description   "description"',
            'EB Platform - Checking EB Environment health',
            'EB Platform - Compressing source',
            '* Local Path: /workspace/job/.',
            '* Temp Artifact: /workspace/build_export.compressed',
            'EB Platform - Uploading artifacts to S3 bucket',
            'EB Platform - Deploying artifact to ElasticBeanstalk',
            'EB Platform - Checking EB Environment health after deployment'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(true, $actual);
    }


    public function generateMockExecution()
    {
        return new JobExecution('eb', 'deploy', []);
    }

    public function generateMockRelease()
    {
        return (new Release('1234'))
            ->withEnvironment(
                (new Environment('1234'))
                    ->withName('UnitTestEnv')
            )
            ->withBuild(new Build('1234'));
    }

    public function configuration()
    {
        return [
            'sdk' => [
                's3' => $this->s3,
                'eb' => $this->eb
            ],
            'region' => 'us-test-1',

            'application' => 'application',
            'environment' => 'environment',

            'bucket' => 'bucket',
            'method' => 'artifact',

            'local_path' => '.',
            'remote_path' => 'file.zip',
            'deployment_description' => 'description'
        ];
    }
}
