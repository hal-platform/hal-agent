<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux;

use Hal\Agent\Build\Linux\Steps\Configurator;
use Hal\Agent\Build\Linux\Steps\Cleaner;
use Hal\Agent\Build\Linux\Steps\Exporter;
use Hal\Agent\Build\Linux\Steps\Importer;
use Hal\Agent\Build\Linux\Steps\Packer;
use Hal\Agent\Build\Linux\Steps\Unpacker;
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
    public $cleaner;

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
    }

    public function testSuccess()
    {
        $build = $this->generateMockBuild();

        $config = [
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
        ];

        $properties = [
            'build' => $build,
            'workspace_path' => '/path/to/workspace',
            'encrypted' => [
                'ENCRYPTED_VAR' => '1234'
            ]
        ];

        $platformConfig = [
            'builder_connection' => 'user@builder.example.com',
            'remote_file' => '/remote/build.tgz',
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
            ->with('/path/to/workspace/build', '/path/to/workspace/build_export.tgz', 'user@builder.example.com', '/remote/build.tgz')
            ->once()
            ->andReturn(true);

        $this->builder
            ->shouldReceive('__invoke')
            ->with('1234', 'my-project-image:latest', 'user@builder.example.com', '/remote/build.tgz', $config['build'], [
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
            ->with('/path/to/workspace/build', '/path/to/workspace/build_import.tgz', 'user@builder.example.com', '/remote/build.tgz')
            ->once()
            ->andReturn(true);

        $this->cleaner
            ->shouldReceive('__invoke')
            ->with('user@builder.example.com', '/remote/build.tgz')
            ->once()
            ->andReturn(true);

        $platform = new LinuxBuildPlatform(
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

        $actual = $platform($build, $config, $properties);

        $expected = [
            'Linux Platform - Validating Linux configuration',
            'Platform configuration:',
            '  builder_connection      "user@builder.example.com"',
            '  remote_file             "/remote/build.tgz"',

            'Linux Platform - Exporting files to build server',
            ' * Workspace: /path/to/workspace/build',
            ' * Local File: /path/to/workspace/build_export.tgz',
            ' * Remote File: /remote/build.tgz',

            'Linux Platform - Running build steps',

            'Linux Platform - Importing artifacts from build server',
            ' * Workspace: /path/to/workspace/build',
            ' * Remote File: /remote/build.tgz',
            ' * Local File: /path/to/workspace/build_import.tgz',

            '! [NOTE] Cleaning up remote builder instance "user@builder.example.com"',
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(true, $actual);
    }

    public function testFailOnConfigurator()
    {
        $build = $this->generateMockBuild();

        $config = [];
        $properties = [];

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
            $this->cleaner,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $config, $properties);

        $expected = [
            '[ERROR] Linux build platform is not configured correctly'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testFailOnExport()
    {
        $build = $this->generateMockBuild();

        $config = [];
        $properties = [
            'workspace_path' => ''
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($build)
            ->andReturn([
                'builder_connection' => '',
                'remote_file' => ''
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
            $this->cleaner,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $config, $properties);

        $expected = [
            '[ERROR] Failed to export build to build system'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testFailOnDecryptingConfiguration()
    {
        $build = $this->generateMockBuild();

        $config = [
            'env' => []
        ];
        $properties = [
            'workspace_path' => '',
            'encrypted' => ['TEST_VAR' => '']
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($build)
            ->andReturn([
                'builder_connection' => '',
                'remote_file' => '',
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
            $this->cleaner,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $config, $properties);

        $expected = [
            '[ERROR] An error occured while decrypting encrypted configuration'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testFailOnBuild()
    {
        $build = $this->generateMockBuild();

        $config = [
            'env' => []
        ];
        $properties = [
            'workspace_path' => '',
            'encrypted' => []
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($build)
            ->andReturn([
                'builder_connection' => '',
                'remote_file' => '',
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
            $this->cleaner,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $config, $properties);

        $this->assertSame(false, $actual);
    }

    public function testEncryptedPropertiesMergedIntoEnv()
    {
        $build = $this->generateMockBuild();

        $config = [
            'env' => [
                'global' => [
                    'CONFIG_1_VAR' => 'ABCD',
                    'CONFIG_2_VAR' => 'EFGH',
                ]
            ]
        ];
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
                'builder_connection' => '',
                'remote_file' => '',
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
            $this->cleaner,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $config, $properties);

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

        $config = [
            'env' => [
                'global' => [
                    'GLOBAL_DERP' => '1234',
                    'OVERRIDDEN' => '5678',
                ],
                'test' => [
                    'TEST_DERP' => '8765',
                    'OVERRIDDEN' => '4321',
                ]
            ]
        ];
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
                'builder_connection' => '',
                'remote_file' => '',
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
            $this->cleaner,

            'default-image'
        );
        $platform->setIO($this->io());

        $actual = $platform($build, $config, $properties);

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
