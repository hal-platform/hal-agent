<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\CodeDeploy;

use Hal\Agent\AWS\S3Uploader;
use Aws\CodeDeploy\CodeDeployClient;
use AWS\S3\S3Client;
use Hal\Agent\Deploy\CodeDeploy\Steps\Compressor;
use Hal\Agent\Deploy\CodeDeploy\Steps\Configurator;
use Hal\Agent\Deploy\CodeDeploy\Steps\Deployer;
use Hal\Agent\Deploy\CodeDeploy\Steps\Verifier;
use Hal\Agent\JobExecution;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\IOTestCase;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Release;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class CodeDeployDeployPlatformTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;

    public $cd;
    public $s3;

    public $compressor;
    public $configurator;
    public $deployer;
    public $uploader;
    public $verifier;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);

        $this->cd = Mockery::Mock(CodeDeployClient::class);
        $this->s3 = Mockery::Mock(S3Client::class);

        $this->compressor = Mockery::mock(Compressor::class);
        $this->configurator = Mockery::mock(Configurator::class);
        $this->deployer = Mockery::mock(Deployer::class);
        $this->uploader = Mockery::mock(S3Uploader::class);
        $this->verifier = Mockery::mock(Verifier::class);
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
            ->with('failure', 'CodeDeploy deploy platform is not configured correctly')
            ->once();

        $platform = new CodeDeployDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->compressor,
            $this->uploader,
            $this->deployer
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] CodeDeploy deploy platform is not configured correctly'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testInvalidLastDeploymentHealthFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($job)
            ->andReturn([
                'sdk' => [
                    's3' => $this->s3,
                    'cd' => $this->cd
                ],
                'local_path' => '.',
                'bucket' => 'bucket',
                'remote_path' => 'remote.tar',
                'application' => 'app',
                'group' => 'grp',
                'configuration' => 'cfg',
                'deployment_description' => 'uri'
            ]);

        $this->verifier
            ->shouldReceive('isDeploymentGroupHealthy')
            ->with($this->cd, 'app', 'grp')
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any())
            ->once();

        $platform = new CodeDeployDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->compressor,
            $this->uploader,
            $this->deployer
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);

        $this->assertSame(false, $actual);
    }

    public function testFailedCompressionFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($job)
            ->andReturn([
                'sdk' => [
                    's3' => $this->s3,
                    'cd' => $this->cd
                ],
                'local_path' => '.',
                'bucket' => 'bucket',
                'remote_path' => 'remote.tar',
                'application' => 'app',
                'group' => 'grp',
                'configuration' => 'cfg',
                'deployment_description' => 'uri'
            ]);

        $this->verifier
            ->shouldReceive('isDeploymentGroupHealthy')
            ->with($this->cd, 'app', 'grp')
            ->andReturn(true);

        $this->compressor
            ->shouldReceive('__invoke')
            ->with('/workspace/job/.', '/workspace/build_export.compressed', 'remote.tar')
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any())
            ->once();

        $platform = new CodeDeployDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->compressor,
            $this->uploader,
            $this->deployer
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);

        $this->assertSame(false, $actual);
    }

    public function testFailedUploadFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($job)
            ->andReturn([
                'sdk' => [
                    's3' => $this->s3,
                    'cd' => $this->cd
                ],
                'local_path' => '.',
                'bucket' => 'bucket',
                'remote_path' => 'remote.tar',
                'application' => 'app',
                'group' => 'grp',
                'configuration' => 'cfg',
                'deployment_description' => 'uri'
            ]);

        $this->verifier
            ->shouldReceive('isDeploymentGroupHealthy')
            ->with($this->cd, 'app', 'grp')
            ->andReturn(true);

        $this->compressor
            ->shouldReceive('__invoke')
            ->with('/workspace/job/.', '/workspace/build_export.compressed', 'remote.tar')
            ->andReturn(true);

        $this->uploader
            ->shouldReceive('__invoke')
            ->with($this->s3, '/workspace/build_export.compressed', 'bucket', 'remote.tar', ['Job' => '1234', 'Environment' => 'UnitTestEnv'])
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any())
            ->once();

        $platform = new CodeDeployDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->compressor,
            $this->uploader,
            $this->deployer
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);

        $this->assertSame(false, $actual);
    }

    public function testFailedDeployFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($job)
            ->andReturn([
                'sdk' => [
                    's3' => $this->s3,
                    'cd' => $this->cd
                ],
                'local_path' => '.',
                'bucket' => 'bucket',
                'remote_path' => 'remote.tar',
                'application' => 'app',
                'group' => 'grp',
                'configuration' => 'cfg',
                'deployment_description' => 'uri'
            ]);

        $this->verifier
            ->shouldReceive('isDeploymentGroupHealthy')
            ->with($this->cd, 'app', 'grp')
            ->andReturn(true);

        $this->compressor
            ->shouldReceive('__invoke')
            ->with('/workspace/job/.', '/workspace/build_export.compressed', 'remote.tar')
            ->andReturn(true);

        $this->uploader
            ->shouldReceive('__invoke')
            ->with($this->s3, '/workspace/build_export.compressed', 'bucket', 'remote.tar', ['Job' => '1234', 'Environment' => 'UnitTestEnv'])
            ->andReturn(true);

        $this->deployer
            ->shouldReceive('__invoke')
            ->with($job, $this->cd, 'bucket', 'remote.tar', 'app', 'grp', 'cfg', 'uri')
            ->andReturn(null);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any())
            ->once();

        $platform = new CodeDeployDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->compressor,
            $this->uploader,
            $this->deployer
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);

        $this->assertSame(false, $actual);
    }

    public function testDeployVerificationTimeoutFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($job)
            ->andReturn([
                'sdk' => [
                    's3' => $this->s3,
                    'cd' => $this->cd
                ],
                'local_path' => '.',
                'bucket' => 'bucket',
                'remote_path' => 'remote.tar',
                'application' => 'app',
                'group' => 'grp',
                'configuration' => 'cfg',
                'deployment_description' => 'uri'
            ]);

        $this->verifier
            ->shouldReceive('isDeploymentGroupHealthy')
            ->with($this->cd, 'app', 'grp')
            ->andReturn(true);

        $this->compressor
            ->shouldReceive('__invoke')
            ->with('/workspace/job/.', '/workspace/build_export.compressed', 'remote.tar')
            ->andReturn(true);

        $this->uploader
            ->shouldReceive('__invoke')
            ->with($this->s3, '/workspace/build_export.compressed', 'bucket', 'remote.tar', ['Job' => '1234', 'Environment' => 'UnitTestEnv'])
            ->andReturn(true);

        $this->deployer
            ->shouldReceive('__invoke')
            ->with($job, $this->cd, 'bucket', 'remote.tar', 'app', 'grp', 'cfg', 'uri')
            ->andReturn(['codeDeployID' => '1234']);

        $this->verifier
            ->shouldReceive('waitForHealth')
            ->with($this->cd, '1234')
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any())
            ->once();

        $platform = new CodeDeployDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->compressor,
            $this->uploader,
            $this->deployer
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);

        $this->assertSame(false, $actual);
    }

    public function testInvalidDeployStatusFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($job)
            ->andReturn([
                'sdk' => [
                    's3' => $this->s3,
                    'cd' => $this->cd
                ],
                'local_path' => '.',
                'bucket' => 'bucket',
                'remote_path' => 'remote.tar',
                'application' => 'app',
                'group' => 'grp',
                'configuration' => 'cfg',
                'deployment_description' => 'uri'
            ]);

        $this->verifier
            ->shouldReceive('isDeploymentGroupHealthy')
            ->with($this->cd, 'app', 'grp')
            ->andReturn(true);

        $this->compressor
            ->shouldReceive('__invoke')
            ->with('/workspace/job/.', '/workspace/build_export.compressed', 'remote.tar')
            ->andReturn(true);

        $this->uploader
            ->shouldReceive('__invoke')
            ->with($this->s3, '/workspace/build_export.compressed', 'bucket', 'remote.tar', ['Job' => '1234', 'Environment' => 'UnitTestEnv'])
            ->andReturn(true);

        $this->deployer
            ->shouldReceive('__invoke')
            ->with($job, $this->cd, 'bucket', 'remote.tar', 'app', 'grp', 'cfg', 'uri')
            ->andReturn(['codeDeployID' => '1234']);

        $this->verifier
            ->shouldReceive('waitForHealth')
            ->with($this->cd, '1234')
            ->andReturn(true);

        $this->verifier
            ->shouldReceive('checkDeploymentHealth')
            ->with($this->cd, '1234')
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any())
            ->once();

        $platform = new CodeDeployDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->compressor,
            $this->uploader,
            $this->deployer
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);

        $this->assertSame(false, $actual);
    }

    public function testSuccess()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [
            'workspace_path' => '/workspace'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($job)
            ->andReturn([
                'sdk' => [
                    's3' => $this->s3,
                    'cd' => $this->cd
                ],
                'local_path' => '.',
                'bucket' => 'bucket',
                'remote_path' => 'remote.tar',
                'application' => 'app',
                'group' => 'grp',
                'configuration' => 'cfg',
                'deployment_description' => 'uri'
            ]);

        $this->verifier
            ->shouldReceive('isDeploymentGroupHealthy')
            ->with($this->cd, 'app', 'grp')
            ->andReturn(true);

        $this->compressor
            ->shouldReceive('__invoke')
            ->with('/workspace/job/.', '/workspace/build_export.compressed', 'remote.tar')
            ->andReturn(true);

        $this->uploader
            ->shouldReceive('__invoke')
            ->with($this->s3, '/workspace/build_export.compressed', 'bucket', 'remote.tar', ['Job' => '1234', 'Environment' => 'UnitTestEnv'])
            ->andReturn(true);

        $this->deployer
            ->shouldReceive('__invoke')
            ->with($job, $this->cd, 'bucket', 'remote.tar', 'app', 'grp', 'cfg', 'uri')
            ->andReturn(['codeDeployID' => '1234']);

        $this->verifier
            ->shouldReceive('waitForHealth')
            ->with($this->cd, '1234')
            ->andReturn(true);

        $this->verifier
            ->shouldReceive('checkDeploymentHealth')
            ->with($this->cd, '1234')
            ->andReturn(true);

        $platform = new CodeDeployDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->compressor,
            $this->uploader,
            $this->deployer
        );
        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);

        $this->assertSame(true, $actual);
    }

    public function generateMockExecution()
    {
        return new JobExecution('cd', 'deploy', []);
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
