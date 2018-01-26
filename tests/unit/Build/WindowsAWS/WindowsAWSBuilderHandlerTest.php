<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\Ec2\Ec2Client;
use Aws\S3\S3Client;
use Aws\Ssm\SsmClient;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Environment;
use Symfony\Component\Console\Output\BufferedOutput;

class WindowsAWSBuildHandlerTest extends MockeryTestCase
{
    public $output;
    public $logger;

    public $finder;
    public $preparer;
    public $exporter;
    public $builder;
    public $importer;
    public $cleaner;
    public $authenticator;
    public $encryptedResolver;

    public $s3;
    public $ssm;
    public $ec2;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock(EventLogger::class);

        $this->finder = Mockery::mock(BuilderFinder::class);
        $this->preparer = Mockery::mock(Preparer::class);
        $this->exporter = Mockery::mock(Exporter::class);
        $this->builder = Mockery::mock(BuilderInterface::class);
        $this->importer = Mockery::mock(Importer::class);
        $this->cleaner = Mockery::mock(Cleaner::class);
        $this->authenticator = Mockery::mock(AWSAuthenticator::class);
        $this->encryptedResolver = Mockery::mock(EncryptedPropertyResolver::class);

        $this->s3 = Mockery::mock(S3Client::class);
        $this->ssm = Mockery::mock(SsmClient::class);
        $this->ec2 = Mockery::mock(Ec2Client::class);
    }

    public function testSuccess()
    {
        $properties = [
            'build' => $this->buildMockBuild(),
            'windows' => [
                'region' => 'us-east-1',
                'credential' => '',
                'instanceFilter' => 'Name=windows-builder',
                'bucket' => 'hal-transfer-bucket',
                'objectInput' => 'b1234.tar.gz',
                'objectOutput' => 'b1234_output.tgz',
                'environmentVariables' => [],
            ],
            'configuration' => [
                'image' => 'microsoft/windowsservercore',
                'build' => ['cmd1', 'cmd2'],
                'env' => [],
            ],
            'location' => [
                'path' => '/build',
                'windowsInputArchive' => '/tmp/builds/local.derp.tar.gz',
                'windowsOutputArchive' => '/tmp/builds/import.derp.tar.gz'
            ]
        ];

        $this->authenticator
            ->shouldReceive(['getEC2' => $this->ec2, 'getSSM' => $this->ssm, 'getS3' => $this->s3]);

        $this->finder
            ->shouldReceive('__invoke')
            ->with($this->ec2, 'Name=windows-builder')
            ->andReturn('i-1234')
            ->once();
        $this->preparer
            ->shouldReceive('__invoke')
            ->with($this->ssm, 'i-1234')
            ->andReturn(true)
            ->once();
        $this->exporter
            ->shouldReceive('__invoke')
            ->with($this->s3, $this->ssm, 'i-1234', '/build', '/tmp/builds/local.derp.tar.gz', 'hal-transfer-bucket', 'b1234.tar.gz', '8956')
            ->andReturn(true)
            ->once();

        $this->builder
            ->shouldReceive('setOutput')
            ->once();
        $this->builder
            ->shouldReceive('__invoke')
            ->with($this->ssm, 'microsoft/windowsservercore', 'i-1234', '8956', ['cmd1', 'cmd2'], [])
            ->andReturn(true)
            ->once();

        $this->importer
            ->shouldReceive('__invoke')
            ->with($this->s3, $this->ssm, 'i-1234', '/build', '/tmp/builds/import.derp.tar.gz', 'hal-transfer-bucket', 'b1234_output.tgz', '8956')
            ->andReturn(true)
            ->once();

        $this->cleaner
            ->shouldReceive('__invoke')
            ->with($this->s3, $this->ssm, 'i-1234', 'hal-transfer-bucket', Mockery::any(), '8956')
            ->andReturn(true)
            ->once();

        $handler = new WindowsAWSBuildHandler(
            $this->logger,
            $this->finder,
            $this->preparer,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->authenticator,
            $this->encryptedResolver,
            'default-image'
        );
        $handler->disableShutdownHandler();
        $handler->setOutput($this->output);

        $actual = $handler($properties['configuration']['build'], $properties);

        $this->assertSame(0, $actual);

        $expected = <<<'OUTPUT'
[Building - Windows] Building on windows
[Building - Windows] Validating windows configuration
[Building - Windows] Authenticating with AWS
[Building - Windows] Preparing and validating build server
[Building - Windows] Exporting files to AWS build server
[Building - Windows] Running build command
[Building - Windows] Importing files from AWS build server
[Shutdown] Cleaning up remote windows AWS artifacts

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testFailSanityCheck()
    {
        $properties = [
            'windows' => [
                'region' => 'us-east-1'
            ],
            'configuration' => [
                'image' => 'nanoserver',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => '',
                'tempArchive' => '/tmp/builds/local.derp.tar.gz'
            ]
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Windows AWS build system is not configured')
            ->once();

        $handler = new WindowsAWSBuildHandler(
            $this->logger,
            $this->finder,
            $this->preparer,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->authenticator,
            $this->encryptedResolver,
            'default-image'
        );
        $handler->disableShutdownHandler();

        $actual = $handler($properties['configuration']['build'], $properties);

        $this->assertSame(1, $actual);
    }

    private function buildMockBuild()
    {
        $push = (new Build)
        ->withId('8956')
        ->withEnvironment(
            (new Environment)
                ->withName('envname')
        )
        ->withApplication(
            (new Application)
                ->withId('repo_id')
        );

        return $push;
    }
}
