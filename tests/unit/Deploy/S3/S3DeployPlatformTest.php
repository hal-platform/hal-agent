<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3;

use AWS\S3\S3Client;
use AWS\CommandInterface;
use Hal\Agent\Deploy\S3\Steps\Configurator;
use Hal\Agent\Deploy\S3\Steps\Validator;
use Hal\Agent\Deploy\S3\Steps\Compressor;
use Hal\Agent\Deploy\S3\Steps\ArtifactUploader;
use Hal\Agent\Deploy\S3\Steps\SyncUploader;
use Hal\Agent\Deploy\S3\Steps\ArtifactVerifier;
use Hal\Agent\JobExecution;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\IOTestCase;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Mockery;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;

class DeployerTest extends IOTestCase
{
    public $logger;
    public $configurator;
    public $authenticator;
    public $validator;
    public $compressor;
    public $artifactUploader;
    public $syncUploader;
    public $verifier;

    public $s3;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->configurator = Mockery::mock(Configurator::class);
        $this->authenticator = Mockery::mock(AWSAuthenticator::class);
        $this->validator = Mockery::mock(Validator::class);
        $this->compressor = Mockery::mock(Compressor::class);
        $this->artifactUploader = Mockery::mock(ArtifactUploader::class);
        $this->syncUploader = Mockery::mock(SyncUploader::class);
        $this->verifier = Mockery::mock(ArtifactVerifier::class);

        $this->s3 = Mockery::Mock(S3Client::class);
    }

    public function tearDown()
    {
        Mockery::close();
    }

    public function testWrongJobTypeFails()
    {
        $job = $this->generateMockBuild();
        $execution = $this->generateMockExecution([]);
        $properties = [];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'The provided job is an invalid type for this job platform')
            ->once();

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->authenticator,
            $this->validator,
            $this->compressor,
            $this->artifactUploader,
            $this->syncUploader,
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
        $execution = $this->generateMockExecution([]);
        $properties = [];

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
            $this->authenticator,
            $this->validator,
            $this->compressor,
            $this->artifactUploader,
            $this->syncUploader,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] S3 deploy platform is not configured correctly'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testUnableToGetS3Fails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution([]);
        $properties = [];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn([
                'aws' => [
                    'region' => '',
                    'credential' => ''
                ],
                's3' => []
            ]);

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn(null);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'AWS credentials could not be authenticated')
            ->once();

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->authenticator,
            $this->validator,
            $this->compressor,
            $this->artifactUploader,
            $this->syncUploader,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] AWS credentials could not be authenticated'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testSourcePathDoesNotExistFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution([]);
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn([
                'aws' => [
                    'region' => '',
                    'credential' => ''
                ],
                's3' => [
                    'src' => 'source'
                ]
            ]);

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn($this->s3);

        $this->validator
            ->shouldReceive('localPathExists')
            ->with('/workspace/job/source')
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'The source could not be validated')
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Either the source or target bucket could not be validated')
            ->once();

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->authenticator,
            $this->validator,
            $this->compressor,
            $this->artifactUploader,
            $this->syncUploader,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] The source could not be validated',
            '[ERROR] Either the source or target bucket could not be validated'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testTargetBucketDoesNotExistFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution([]);
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn([
                'aws' => [
                    'region' => '',
                    'credential' => ''
                ],
                's3' => [
                    'src' => 'source',
                    'bucket' => 'target_bucket'
                ]
            ]);

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn($this->s3);

        $this->validator
            ->shouldReceive('localPathExists')
            ->with('/workspace/job/source')
            ->andReturn(true);

        $this->validator
            ->shouldReceive('bucketExists')
            ->with($this->s3, 'target_bucket')
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'The target bucket could not be validated')
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Either the source or target bucket could not be validated')
            ->once();

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->authenticator,
            $this->validator,
            $this->compressor,
            $this->artifactUploader,
            $this->syncUploader,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] The target bucket could not be validated',
            '[ERROR] Either the source or target bucket could not be validated'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testDirectoryCouldNotBeCompressedFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution([]);
        $properties = [
            'workspace_path' => '/workspace',
            'artifact_stored_file' => '/temp/artifact.tar'
        ];
        $config =[
            'aws' => [
                'region' => '',
                'credential' => ''
            ],
            's3' => [
                'method' => 'artifact',
                'src' => 'source',
                'bucket' => 'target_bucket',
                'file' => 'target_file'
            ]
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn($this->s3);

        $this->validator
            ->shouldReceive('localPathExists')
            ->with('/workspace/job/source')
            ->andReturn(true);

        $this->validator
            ->shouldReceive('bucketExists')
            ->with($this->s3, 'target_bucket')
            ->andReturn(true);

        $this->validator
            ->shouldReceive('isDirectory')
            ->with('/workspace/job/source')
            ->andReturn(true);

        $this->compressor
            ->shouldReceive('__invoke')
            ->with('/workspace/job/source', '/temp/artifact.tar', 'target_file')
            ->andReturn(null);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'The source directory could not be compressed')
            ->once();

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->authenticator,
            $this->validator,
            $this->compressor,
            $this->artifactUploader,
            $this->syncUploader,
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

    public function testCouldNotUploadFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution([]);
        $properties = [
            'workspace_path' => '/workspace'
        ];
        $config =[
            'aws' => [
                'region' => '',
                'credential' => ''
            ],
            's3' => [
                'method' => 'sync',
                'src' => 'source',
                'bucket' => 'target_bucket',
                'file' => 'target_file'
            ]
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn($this->s3);

        $this->validator
            ->shouldReceive('localPathExists')
            ->with('/workspace/job/source')
            ->andReturn(true);

        $this->validator
            ->shouldReceive('bucketExists')
            ->with($this->s3, 'target_bucket')
            ->andReturn(true);

        $this->syncUploader
            ->shouldReceive('__invoke')
            ->with(
                $this->s3,
                '/workspace/job/source',
                'target_bucket',
                'target_file',
                [
                    'Build' => '1234',
                    'Release' => '1234',
                    'Environment' => 'UnitTestEnv'
                ]
            )
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'The file(s) could not be uploaded to the S3 Bucket')
            ->once();

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->authenticator,
            $this->validator,
            $this->compressor,
            $this->artifactUploader,
            $this->syncUploader,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] The file(s) could not be uploaded to the S3 Bucket'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testUploadCredentialsExceptionFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution([]);
        $properties = [
            'workspace_path' => '/workspace'
        ];
        $config =[
            'aws' => [
                'region' => '',
                'credential' => ''
            ],
            's3' => [
                'method' => 'sync',
                'src' => 'source',
                'bucket' => 'target_bucket',
                'file' => 'target_file'
            ]
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn($this->s3);

        $this->validator
            ->shouldReceive('localPathExists')
            ->with('/workspace/job/source')
            ->andReturn(true);

        $this->validator
            ->shouldReceive('bucketExists')
            ->with($this->s3, 'target_bucket')
            ->andReturn(true);

        $this->syncUploader
            ->shouldReceive('__invoke')
            ->with(
                $this->s3,
                '/workspace/job/source',
                'target_bucket',
                'target_file',
                [
                    'Build' => '1234',
                    'Release' => '1234',
                    'Environment' => 'UnitTestEnv'
                ]
            )
            ->andThrow(new CredentialsException());

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'AWS credentials could not be authenticated')
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'The file(s) could not be uploaded to the S3 Bucket')
            ->once();

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->authenticator,
            $this->validator,
            $this->compressor,
            $this->artifactUploader,
            $this->syncUploader,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] AWS credentials could not be authenticated',
            '[ERROR] The file(s) could not be uploaded to the S3 Bucket'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testArtifactCouldNotBeVerifiedFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution([]);
        $properties = [
            'workspace_path' => '/workspace',
            'artifact_stored_file' => '/temp/artifact.tar'
        ];
        $config =[
            'aws' => [
                'region' => '',
                'credential' => ''
            ],
            's3' => [
                'method' => 'artifact',
                'src' => 'source',
                'bucket' => 'target_bucket',
                'file' => 'target_file'
            ]
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn($this->s3);

        $this->validator
            ->shouldReceive('localPathExists')
            ->with('/workspace/job/source')
            ->andReturn(true);

        $this->validator
            ->shouldReceive('bucketExists')
            ->with($this->s3, 'target_bucket')
            ->andReturn(true);

        $this->validator
            ->shouldReceive('isDirectory')
            ->with('/workspace/job/source')
            ->andReturn(true);

        $this->compressor
            ->shouldReceive('__invoke')
            ->with('/workspace/job/source', '/temp/artifact.tar', 'target_file')
            ->andReturn('/temp/artifact.tar');

        $this->artifactUploader
            ->shouldReceive('__invoke')
            ->with(
                $this->s3,
                '/temp/artifact.tar',
                'target_bucket',
                'target_file',
                [
                    'Build' => '1234',
                    'Release' => '1234',
                    'Environment' => 'UnitTestEnv'
                ]
            )
            ->andReturn(true);

        $this->verifier
            ->shouldReceive('__invoke')
            ->with($this->s3, 'target_bucket', 'target_file')
            ->andThrow(new AwsException('Forced AWS Exception', Mockery::Mock(CommandInterface::class)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Forced AWS Exception')
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'The artifact could not be verified as uploaded')
            ->once();

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->authenticator,
            $this->validator,
            $this->compressor,
            $this->artifactUploader,
            $this->syncUploader,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] The artifact could not be verified as uploaded'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testSuccess()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution([]);
        $properties = [
            'workspace_path' => '/workspace',
            'artifact_stored_file' => '/temp/artifact.tar'
        ];
        $config =[
            'aws' => [
                'region' => 'us-test-1',
                'credential' => ''
            ],
            's3' => [
                'method' => 'artifact',
                'src' => 'source',
                'bucket' => 'target_bucket',
                'file' => 'target_file'
            ]
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn($this->s3);

        $this->validator
            ->shouldReceive('localPathExists')
            ->with('/workspace/job/source')
            ->andReturn(true);

        $this->validator
            ->shouldReceive('bucketExists')
            ->with($this->s3, 'target_bucket')
            ->andReturn(true);

        $this->validator
            ->shouldReceive('isDirectory')
            ->with('/workspace/job/source')
            ->andReturn(true);

        $this->compressor
            ->shouldReceive('__invoke')
            ->with('/workspace/job/source', '/temp/artifact.tar', 'target_file')
            ->andReturn('/temp/artifact.tar');

        $this->artifactUploader
            ->shouldReceive('__invoke')
            ->with(
                $this->s3,
                '/temp/artifact.tar',
                'target_bucket',
                'target_file',
                [
                    'Build' => '1234',
                    'Release' => '1234',
                    'Environment' => 'UnitTestEnv'
                ]
            )
            ->andReturn(true);

        $this->verifier
            ->shouldReceive('__invoke')
            ->with($this->s3, 'target_bucket', 'target_file')
            ->andReturn(true);

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->authenticator,
            $this->validator,
            $this->compressor,
            $this->artifactUploader,
            $this->syncUploader,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            'S3 Platform - Validating S3 configuration',
            'Platform configuration:',
            '  aws             {',
            '                      "region": "us-test-1",',
            '                      "credential": ""',
            '                  }',
            '  s3              {',
            '                      "method": "artifact",',
            '                      "src": "source",',
            '                      "bucket": "target_bucket",',
            '                      "file": "target_file"',
            '                  }',

            'S3 Platform - Authenticating with AWS',
            ' * Region: us-test-1',

            'S3 Platform - Validating source and target bucket',
            ' * Source: /workspace/job/source',
            ' * Target: target_bucket',

            'S3 Platform - Compressing source',
            ' * Original: /workspace/job/source',
            ' * Compressed: /temp/artifact.tar',

            'S3 Platform - Uploading file(s) to S3 bucket',
            'Metadata:',
            '  Build           "1234"',
            '  Release         "1234"',
            '  Environment     "UnitTestEnv"',

            'S3 Platform - Verifying successful artifact upload',
            '! [NOTE] Artifact upload successfully verified'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(true, $actual);
    }

    public function testNonDirectorySourcesSkipCompression()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution([]);
        $properties = [
            'workspace_path' => '/workspace'
        ];
        $config =[
            'aws' => [
                'region' => 'us-test-1',
                'credential' => ''
            ],
            's3' => [
                'method' => 'artifact',
                'src' => 'source',
                'bucket' => 'target_bucket',
                'file' => 'target_file'
            ]
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn($this->s3);

        $this->validator
            ->shouldReceive('localPathExists')
            ->with('/workspace/job/source')
            ->andReturn(true);

        $this->validator
            ->shouldReceive('bucketExists')
            ->with($this->s3, 'target_bucket')
            ->andReturn(true);

        $this->validator
            ->shouldReceive('isDirectory')
            ->with('/workspace/job/source')
            ->andReturn(false);

        $this->artifactUploader
            ->shouldReceive('__invoke')
            ->with(
                $this->s3,
                '/workspace/job/source',
                'target_bucket',
                'target_file',
                [
                    'Build' => '1234',
                    'Release' => '1234',
                    'Environment' => 'UnitTestEnv'
                ]
            )
            ->andReturn(true);

        $this->verifier
            ->shouldReceive('__invoke')
            ->with($this->s3, 'target_bucket', 'target_file')
            ->andReturn(true);

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->authenticator,
            $this->validator,
            $this->compressor,
            $this->artifactUploader,
            $this->syncUploader,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '! [NOTE] Skipping compression step: source is not a directory'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(true, $actual);
    }

    public function testSyncSkipsCompressionAndVerification()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution([]);
        $properties = [
            'workspace_path' => '/workspace'
        ];
        $config =[
            'aws' => [
                'region' => 'us-test-1',
                'credential' => ''
            ],
            's3' => [
                'method' => 'sync',
                'src' => 'source',
                'bucket' => 'target_bucket',
                'file' => 'target_file'
            ]
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn($this->s3);

        $this->validator
            ->shouldReceive('localPathExists')
            ->with('/workspace/job/source')
            ->andReturn(true);

        $this->validator
            ->shouldReceive('bucketExists')
            ->with($this->s3, 'target_bucket')
            ->andReturn(true);

        $this->syncUploader
            ->shouldReceive('__invoke')
            ->with(
                $this->s3,
                '/workspace/job/source',
                'target_bucket',
                'target_file',
                [
                    'Build' => '1234',
                    'Release' => '1234',
                    'Environment' => 'UnitTestEnv'
                ]
            )
            ->andReturn(true);

        $platform = new S3DeployPlatform(
            $this->logger,
            $this->configurator,
            $this->authenticator,
            $this->validator,
            $this->compressor,
            $this->artifactUploader,
            $this->syncUploader,
            $this->verifier
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '! [NOTE] Skipping compression step: in sync mode',
            '! [NOTE] Skipping artifact verification step: in sync mode'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(true, $actual);
    }

    public function generateMockExecution(array $config)
    {
        return new JobExecution('s3', 'deploy', $config);
    }

    public function generateMockRelease()
    {
        $build = $this->generateMockBuild();
        $environment = $this->generateMockEnvironment();

        return (new Release('1234'))
            ->withBuild($build)
            ->withEnvironment($environment);
    }

    public function generateMockBuild()
    {
        return (new Build('1234'));
    }

    public function generateMockEnvironment()
    {
        return (new Environment('1234'))
            ->withName('UnitTestEnv');
    }
}
