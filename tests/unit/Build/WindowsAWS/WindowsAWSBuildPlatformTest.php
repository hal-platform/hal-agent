<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\S3\S3Client;
use Aws\Ssm\SsmClient;
use Hal\Agent\Build\WindowsAWS\Steps\Cleaner;
use Hal\Agent\Build\WindowsAWS\Steps\Configurator;
use Hal\Agent\Build\WindowsAWS\Steps\Exporter;
use Hal\Agent\Build\WindowsAWS\Steps\Importer;
use Hal\Agent\JobExecution;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\IOTestCase;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\Console\Output\BufferedOutput;

class WindowsAWSBuildPlatformTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $decrypter;

    public $configurator;
    public $exporter;
    public $builder;
    public $importer;
    public $cleaner;

    public $s3;
    public $ssm;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->decrypter = Mockery::mock(EncryptedPropertyResolver::class, [
            'decryptProperties' => []
        ]);

        $this->configurator = Mockery::mock(Configurator::class);
        $this->exporter = Mockery::mock(Exporter::class);
        $this->builder = Mockery::mock(BuilderInterface::class, [
            'setIO' => null
        ]);
        $this->importer = Mockery::mock(Importer::class);
        $this->cleaner = Mockery::mock(Cleaner::class, [
            '__invoke' => true
        ]);

        $this->s3 = Mockery::mock(S3Client::class);
        $this->ssm = Mockery::mock(SsmClient::class);
    }

    public function testSuccess()
    {
        $build = $this->generateMockBuild();
        $execution = $this->generateMockExecution([
            'image' => 'my-project-image:latest',
            'build' => [
                'step1',
                'step2 arg --flag'
            ],
            'env' => [
                'global' => [
                    'CONFIG_VAR' => '5678'
                ]
            ],
        ]);

        $properties = [
            'workspace_path' => '/path/to/workspace',
            'encrypted' => [
                'ENCRYPTED_VAR' => '1234'
            ]
        ];

        $platformConfig = [
            'sdk' => [
                's3' => $this->s3,
                'ssm' => $this->ssm
            ],
            'instance_id' => 'i-1234',
            'bucket' => 'hal-transfer-bucket',
            's3_input_object' => '1234_export.tgz',
            's3_output_object' => '1234_import.tgz',
            'environment_variables' => [
                'PLATFORM_VAR' => 'windows'
            ],
        ];

        $this->decrypter
            ->shouldReceive('decryptProperties')
            ->with($properties['encrypted'])
            ->once()
            ->andReturn($properties['encrypted']);

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($build)
            ->once()
            ->andReturn($platformConfig);

        $this->exporter
            ->shouldReceive('__invoke')
            ->with($this->s3, $this->ssm, 'i-1234', '1234', '/path/to/workspace/build', '/path/to/workspace/build_export.tgz', 'hal-transfer-bucket', '1234_export.tgz')
            ->once()
            ->andReturn(true);

        $this->builder
            ->shouldReceive('__invoke')
            ->with('1234', 'my-project-image:latest', $this->ssm, 'i-1234', ['step1', 'step2 arg --flag'], [
                'PLATFORM_VAR' => 'windows',
                'ENCRYPTED_ENCRYPTED_VAR' => '1234',
                'CONFIG_VAR' => '5678'
            ])
            ->once()
            ->andReturn(true);
        $this->builder
            ->shouldReceive('setIO')
            ->once();

        $this->importer
            ->shouldReceive('__invoke')
            ->with($this->s3, $this->ssm, 'i-1234', '1234', '/path/to/workspace/build', '/path/to/workspace/build_import.tgz', 'hal-transfer-bucket', '1234_import.tgz')
            ->once()
            ->andReturn(true);

        $this->cleaner
            ->shouldReceive('__invoke')
            ->with($this->s3, $this->ssm, 'i-1234', '1234', 'hal-transfer-bucket', ['1234_export.tgz', '1234_import.tgz'])
            ->once()
            ->andReturn(true);

        $platform = new WindowsAWSBuildPlatform(
            $this->logger,
            $this->decrypter,

            $this->configurator,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $execution, $properties);

        $expected = [
            'Windows Docker Platform - Validating Windows configuration',
            'Platform configuration:',
            '  instance_id             "i-1234"',
            '  bucket                  "hal-transfer-bucket"',
            '  s3_input_object         "1234_export.tgz"',
            '  s3_output_object        "1234_import.tgz"',

            'Windows Docker Platform - Exporting files to AWS environment',
            ' * Workspace: /path/to/workspace/build',
            ' * Local File: /path/to/workspace/build_export.tgz',
            ' * S3 Object: hal-transfer-bucket/1234_export.tgz',
            ' * S3 Build Artifact: hal-transfer-bucket/1234_import.tgz',

            'Windows Docker Platform - Running build steps',

            'Windows Docker Platform - Importing artifacts from AWS environment',
            ' * Workspace: /path/to/workspace/build',
            ' * Remote Object: hal-transfer-bucket/1234_import.tgz',
            ' * Local File: /path/to/workspace/build_import.tgz',

            '! [NOTE] Cleaning up AWS builder instance "i-1234" '
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(true, $actual);
    }

    public function testFailOnConfigurator()
    {
        $build = $this->generateMockBuild();
        $execution = $this->generateMockExecution([
            'build' => []
        ]);

        $properties = [];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($build)
            ->once()
            ->andReturnNull();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Windows Docker build platform is not configured correctly')
            ->once();

        $platform = new WindowsAWSBuildPlatform(
            $this->logger,
            $this->decrypter,

            $this->configurator,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $execution, $properties);

        $expected = [
            '[ERROR] Windows Docker build platform is not configured correctly'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testFailOnExport()
    {
        $build = $this->generateMockBuild();
        $execution = $this->generateMockExecution([
            'build' => []
        ]);

        $properties = [
            'workspace_path' => ''
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($build)
            ->andReturn([
                'sdk' => [
                    's3' => $this->s3,
                    'ssm' => $this->ssm
                ],
                'instance_id' => 'i-1234',
                'bucket' => 'hal-transfer-bucket',
                's3_input_object' => '1234_export.tgz',
                's3_output_object' => '1234_import.tgz',
            ]);

        $this->exporter
            ->shouldReceive('__invoke')
            ->once()
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Failed to export build to build system')
            ->once();

        $platform = new WindowsAWSBuildPlatform(
            $this->logger,
            $this->decrypter,

            $this->configurator,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $execution, $properties);

        $expected = [
            '[ERROR] Failed to export build to build system'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testFailOnDecryptingConfiguration()
    {
        $build = $this->generateMockBuild();
        $execution = $this->generateMockExecution([
            'env' => [],
            'build' => []
        ]);

        $properties = [
            'workspace_path' => '',
            'encrypted' => ['TEST_VAR' => '']
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($build)
            ->andReturn([
                'sdk' => [
                    's3' => $this->s3,
                    'ssm' => $this->ssm
                ],
                'instance_id' => 'i-1234',
                'bucket' => 'hal-transfer-bucket',
                's3_input_object' => '1234_export.tgz',
                's3_output_object' => '1234_import.tgz',
                'environment_variables' => [
                    'PLATFORM_VAR' => 'windows'
                ],
            ]);

        $this->exporter
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->decrypter
            ->shouldReceive('decryptProperties')
            ->once()
            ->andReturn([]);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'An error occured while decrypting encrypted configuration')
            ->once();

        $platform = new WindowsAWSBuildPlatform(
            $this->logger,
            $this->decrypter,

            $this->configurator,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $execution, $properties);

        $expected = [
            '[ERROR] An error occured while decrypting encrypted configuration'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testFailOnBuild()
    {
        $build = $this->generateMockBuild();
        $execution = $this->generateMockExecution([
            'env' => [],
            'build' => []
        ]);

        $properties = [
            'workspace_path' => '',
            'encrypted' => []
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($build)
            ->andReturn([
                'sdk' => [
                    's3' => $this->s3,
                    'ssm' => $this->ssm
                ],
                'instance_id' => 'i-1234',
                'bucket' => 'hal-transfer-bucket',
                's3_input_object' => '1234_export.tgz',
                's3_output_object' => '1234_import.tgz',
                'environment_variables' => [],
            ]);

        $this->exporter
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->builder
            ->shouldReceive('__invoke')
            ->once()
            ->andReturn(false);

        $platform = new WindowsAWSBuildPlatform(
            $this->logger,
            $this->decrypter,

            $this->configurator,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $execution, $properties);

        $this->assertSame(false, $actual);
    }

    public function generateMockExecution(array $config)
    {
        return new JobExecution('windows', 'build', $config);
    }

    public function generateMockBuild()
    {
        return (new Build('1234'))
            ->withReference('master')
            ->withCommit('7de49f3')
            ->withApplication(
                (new Application('a-1234'))
                    ->withName('derp')
            )
            ->withEnvironment(
                (new Environment('e-1234'))
                    ->withName('staging')
            );
    }
}
