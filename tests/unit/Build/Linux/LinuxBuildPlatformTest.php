<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux;

use Hal\Agent\Build\Linux\Steps\Configurator;
use Hal\Agent\Build\Linux\Steps\Exporter;
use Hal\Agent\Build\Linux\Steps\Importer;
use Hal\Agent\Build\Linux\Steps\Packer;
use Hal\Agent\Build\Linux\Steps\Unpacker;
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

class LinuxBuildPlatformTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $decrypter;

    public $configurator;
    public $exporter;
    public $builder;
    public $importer;

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
            'build' => $build,
            'workspace_path' => '/path/to/5678',
            'encrypted' => [
                'ENCRYPTED_VAR' => '1234'
            ]
        ];

        $platformConfig = [

            'stage_id' => '1234-5678',
            'environment_variables' => [
                'PLATFORM_VAR' => 'linux'
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
            ->with('/path/to/5678/workspace', '/path/to/5678/1234-5678')
            ->once()
            ->andReturn(true);

        $this->builder
            ->shouldReceive('__invoke')
            ->with('1234', 'my-project-image:latest', '/path/to/5678', '/path/to/5678/1234-5678', $execution->steps(), [
                'PLATFORM_VAR' => 'linux',
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
            ->with('/path/to/5678/workspace', '/path/to/5678/1234-5678')
            ->once()
            ->andReturn(true);

        $platform = new LinuxBuildPlatform(
            $this->logger,
            $this->decrypter,

            $this->configurator,
            $this->exporter,
            $this->builder,
            $this->importer,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $execution, $properties);

        $expected = [
            'Linux Platform - Validating Linux configuration',
            'Platform configuration:',
            '  stage_id                "1234-5678"',
            '  environment_variables   {',
            '                              "PLATFORM_VAR": "linux"',
            '                          }',

            'Linux Platform - Exporting artifacts to stage',
            ' * Workspace: /path/to/5678/workspace',
            ' * Stage Path: /path/to/5678/1234-5678',

            'Linux Platform - Running build steps',

            'Linux Platform - Importing artifacts from stage',
            ' * Workspace: /path/to/5678/workspace',
            ' * Stage Path: /path/to/5678/1234-5678',
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

        $properties = [
            'workspace_path' => '/path/to/5678',
            'encrypted' => []
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($build)
            ->once()
            ->andReturnNull();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Linux build platform is not configured correctly')
            ->once();

        $platform = new LinuxBuildPlatform(
            $this->logger,
            $this->decrypter,

            $this->configurator,
            $this->exporter,
            $this->builder,
            $this->importer,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $execution, $properties);

        $expected = [
            '[ERROR] Linux build platform is not configured correctly'
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
            'workspace_path' => '',
            'encrypted' => [],
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($build)
            ->andReturn([
                'stage_id' => '',
                'environment_variables' => []
            ]);

        $this->exporter
            ->shouldReceive('__invoke')
            ->once()
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Failed to export build to build system')
            ->once();

        $platform = new LinuxBuildPlatform(
            $this->logger,
            $this->decrypter,

            $this->configurator,
            $this->exporter,
            $this->builder,
            $this->importer,

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
                'stage_id' => '',
                'environment_variables' => []
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

        $platform = new LinuxBuildPlatform(
            $this->logger,
            $this->decrypter,

            $this->configurator,
            $this->exporter,
            $this->builder,
            $this->importer,

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
                'stage_id' => '',
                'environment_variables' => []
            ]);

        $this->exporter
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->builder
            ->shouldReceive('__invoke')
            ->once()
            ->andReturn(false);

        $platform = new LinuxBuildPlatform(
            $this->logger,
            $this->decrypter,

            $this->configurator,
            $this->exporter,
            $this->builder,
            $this->importer,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $execution, $properties);

        $this->assertSame(false, $actual);
    }

    public function testEncryptedPropertiesMergedIntoEnv()
    {
        $build = $this->generateMockBuild();
        $execution = $this->generateMockExecution([
            'env' => [
                'global' => [
                    'CONFIG_1_VAR' => 'ABCD',
                    'CONFIG_2_VAR' => 'EFGH',
                ]
            ],
            'build' => []
        ]);

        $properties = [
            'workspace_path' => '',
            'encrypted' => [
                'ENCRYPTED_1_VAR' => '1234',
                'ENCRYPTED_2_VAR' => '5678',
            ]
        ];

        $env = [];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($build)
            ->andReturn([
                'stage_id' => '',
                'environment_variables' => [
                    'PLATFORM_1_VAR' => 'WXYZ'
                ]
            ]);

        $this->exporter
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->decrypter
            ->shouldReceive('decryptProperties')
            ->once()
            ->andReturn([
                'ENCRYPTED_1_VAR' => '1234',
                'ENCRYPTED_2_VAR' => '5678',
            ]);

        $this->builder
            ->shouldReceive('__invoke')
            ->with(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::on(function($v) use (&$env) {
                $env = $v;
                return true;
            }))
            ->once()
            ->andReturn(false);

        $platform = new LinuxBuildPlatform(
            $this->logger,
            $this->decrypter,

            $this->configurator,
            $this->exporter,
            $this->builder,
            $this->importer,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $execution, $properties);

        $expected = [
            'PLATFORM_1_VAR' => 'WXYZ',
            'ENCRYPTED_ENCRYPTED_1_VAR' => '1234',
            'ENCRYPTED_ENCRYPTED_2_VAR' => '5678',
            'CONFIG_1_VAR' => 'ABCD',
            'CONFIG_2_VAR' => 'EFGH',
        ];

        $this->assertSame(false, $actual);
        $this->assertSame($expected, $env);
    }

    public function testUserEnvMergedIntoEnv()
    {
        $build = $this->generateMockBuild();
        $execution = $this->generateMockExecution([
            'env' => [
                'global' => [
                    'GLOBAL_DERP' => '1234',
                    'OVERRIDDEN' => '5678',
                ],
                'test' => [
                    'TEST_DERP' => '8765',
                    'OVERRIDDEN' => '4321',
                ]
            ],
            'build' => []
        ]);

        $properties = [
            'workspace_path' => '',
            'encrypted' => [
                'VAL1' => 'encrypted1',
                'VAL2' => 'encrypted2'
            ]
        ];

        $env = [];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($build)
            ->andReturn([
                'stage_id' => '',
                'environment_variables' => [
                    'derp' => 'herp',
                    'HAL_ENVIRONMENT' => 'test'
                ]
            ]);

        $this->exporter
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->decrypter
            ->shouldReceive('decryptProperties')
            ->once()
            ->andReturn([
                'VAL1' => 'encrypted1',
                'VAL2' => 'encrypted2'
            ]);


        $this->builder
            ->shouldReceive('__invoke')
            ->with(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::on(function($v) use (&$env) {
                $env = $v;
                return true;
            }))
            ->once()
            ->andReturn(false);

        $platform = new LinuxBuildPlatform(
            $this->logger,
            $this->decrypter,

            $this->configurator,
            $this->exporter,
            $this->builder,
            $this->importer,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $execution, $properties);

        $expected = [
            'derp' => 'herp',
            'HAL_ENVIRONMENT' => 'test',
            'ENCRYPTED_VAL1' => 'encrypted1',
            'ENCRYPTED_VAL2' => 'encrypted2',
            'GLOBAL_DERP' => '1234',
            'OVERRIDDEN' => '4321',
            'TEST_DERP' => '8765',
        ];

        $this->assertSame(false, $actual);
        $this->assertSame($expected, $env);
    }

    public function generateMockExecution(array $config)
    {
        return new JobExecution('linux', 'build', $config);
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
