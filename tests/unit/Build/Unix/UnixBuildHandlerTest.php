<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Unix;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class UnixBuildHandlerTest extends MockeryTestCase
{
    public $output;
    public $logger;

    public $exporter;
    public $builder;
    public $importer;
    public $cleaner;
    public $decrypter;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock('Hal\Agent\Logger\EventLogger');

        $this->exporter = Mockery::mock('Hal\Agent\Build\Unix\Exporter', ['__invoke' => null]);
        $this->builder = Mockery::mock('Hal\Agent\Build\Unix\DockerBuilder', [
            '__invoke' => null,
            '__destruct' => null,
            'cleanup' => null
        ]);
        $this->importer = Mockery::mock('Hal\Agent\Build\Unix\Importer', ['__invoke' => null]);
        $this->cleaner = Mockery::mock('Hal\Agent\Build\Unix\Cleaner', ['__invoke' => null]);
        $this->decrypter = Mockery::mock('Hal\Agent\Utility\EncryptedPropertyResolver');
    }

    public function testSuccess()
    {
        $properties = [
            'unix' => [
                'environmentVariables' => [],
                'buildUser' => 'testuser',
                'buildServer' => 'buildserver',
                'remoteFile' => '/var/builds/remote.tar.gz'
            ],
            'configuration' => [
                'system' => 'unix',
                'build' => ['cmd1'],
                'env' => []
            ],
            'location' => [
                'path' => '',
                'tempArchive' => '/tmp/builds/local.derp.tar.gz'
            ]
        ];

        $this->exporter
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->builder
            ->shouldReceive('setOutput')
            ->once();
        $this->importer
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();

        $handler = new UnixBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter,
            'default-image'
        );
        $handler->disableShutdownHandler();
        $handler->setOutput($this->output);

        $actual = $handler($properties['configuration']['build'], $properties);

        $this->assertSame(0, $actual);

        $expected = <<<'OUTPUT'
[Building - Unix] Building on unix
[Building - Unix] Validating unix configuration
[Building - Unix] Exporting files to build server
[Building - Unix] Running build command
[Building - Unix] Importing files from build server
[Shutdown] Cleaning up remote unix build server

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testFailSanityCheck()
    {
        $properties = [
            'configuration' => [
                'system' => 'unix',
                'build' => ['cmd1'],
                'buildUser' => 'testuser',
                'buildServer' => 'buildserver',
                'remoteFile' => '/var/builds/remote.tar.gz',
                'env' => []
            ],
            'location' => [
                'path' => '',
                'tempArchive' => '/tmp/builds/local.derp.tar.gz'
            ]
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Unix build system is not configured')
            ->once();

        $handler = new UnixBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter,
            'default-image'
        );
        $handler->disableShutdownHandler();

        $actual = $handler($properties['configuration']['build'], $properties);

        $this->assertSame(100, $actual);
    }

    public function testFailOnBuild()
    {
        $properties = [
            'unix' => [
                'environmentVariables' => [],
                'buildUser' => 'testuser',
                'buildServer' => 'buildserver',
                'remoteFile' => '/var/builds/remote.tar.gz'
            ],
            'configuration' => [
                'system' => 'docker:custom-docker-image',
                'build' => ['cmd1'],
                'env' => []
            ],
            'location' => [
                'path' => '',
                'tempArchive' => '/tmp/builds/local.derp.tar.gz'
            ]
        ];

        $this->exporter
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->builder
            ->shouldReceive('__invoke')
            ->with(
                'custom-docker-image',
                'testuser',
                'buildserver',
                '/var/builds/remote.tar.gz',
                ['cmd1'],
                []
            )
            ->andReturn(false)
            ->once();
        $this->importer
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->never();

        $handler = new UnixBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter,
            'default-image'
        );
        $handler->disableShutdownHandler();

        $actual = $handler($properties['configuration']['build'], $properties);

        $this->assertSame(103, $actual);
    }

    public function testBadDecryptHaltsBuild()
    {
        $properties = [
            'unix' => [
                'environmentVariables' => [
                    'derp' => 'herp'
                ],
                'buildUser' => 'testuser',
                'buildServer' => 'buildserver',
                'remoteFile' => '/var/builds/remote.tar.gz'
            ],
            'configuration' => [
                'system' => 'unix',
                'build' => ['cmd1'],
                'env' => []
            ],
            'location' => [
                'path' => '',
                'tempArchive' => '/tmp/builds/local.derp.tar.gz'
            ],
            'encrypted' => [
                'VAL1' => 'testing1',
                'VAL2' => 'testing2'
            ]
        ];

        $this->decrypter
            ->shouldReceive('decryptProperties')
            ->with([
                'VAL1' => 'testing1',
                'VAL2' => 'testing2'
            ])
            ->andReturn([
                'VAL1' => 'testing1-D',
                // one value missing
            ]);

        $this->decrypter
            ->shouldReceive('mergePropertiesIntoEnv')
            ->never();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', UnixBuildHandler::ERR_BAD_DECRYPT)
            ->once();

        $this->exporter
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->builder
            ->shouldReceive('__invoke')
            ->never();

        $handler = new UnixBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter,
            'default-image'
        );
        $handler->disableShutdownHandler();

        $actual = $handler($properties['configuration']['build'], $properties);
        $this->assertSame(102, $actual);
    }

    public function testEncryptedPropertiesMergedIntoEnv()
    {
        $properties = [
            'unix' => [
                'environmentVariables' => [
                    'derp' => 'herp'
                ],
                'buildUser' => 'testuser',
                'buildServer' => 'buildserver',
                'remoteFile' => '/var/builds/remote2.tar.gz'
            ],
            'configuration' => [
                'system' => 'unix',
                'build' => ['cmd1'],
                'env' => []
            ],
            'location' => [
                'path' => '',
                'tempArchive' => '/tmp/builds/local.derp.tar.gz'
            ],
            'encrypted' => [
                'VAL1' => 'testing1',
                'VAL2' => 'testing2'
            ]
        ];

        $this->decrypter
            ->shouldReceive('decryptProperties')
            ->with([
                'VAL1' => 'testing1',
                'VAL2' => 'testing2'
            ])
            ->andReturn([
                'VAL1' => 'testing1-D',
                'VAL2' => 'testing2-D'
            ]);

        $this->decrypter
            ->shouldReceive('mergePropertiesIntoEnv')
            ->with(
                [
                    'derp' => 'herp'
                ],
                [
                    'VAL1' => 'testing1-D',
                    'VAL2' => 'testing2-D'
                ]
            )
            ->andReturn(['decrypt-output']);

        $this->exporter
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->builder
            ->shouldReceive('__invoke')
            ->with(
                'default-image',
                'testuser',
                'buildserver',
                '/var/builds/remote2.tar.gz',
                ['cmd1'],
                ['decrypt-output']
            )
            ->andReturn(true)
            ->once();
        $this->importer
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();

        $handler = new UnixBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter,
            'default-image'
        );
        $handler->disableShutdownHandler();

        $actual = $handler($properties['configuration']['build'], $properties);
        $this->assertSame(0, $actual);
    }

    public function testUserEnvMergedIntoEnv()
    {
        $properties = [
            'unix' => [
                'environmentVariables' => [
                    'derp' => 'herp',
                    'HAL_ENVIRONMENT' => 'test'
                ],
                'buildUser' => 'testuser',
                'buildServer' => 'buildserver',
                'remoteFile' => ''
            ],
            'configuration' => [
                'system' => 'unix',
                'build' => ['cmd1'],
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
            ],
            'location' => [
                'path' => '',
                'tempArchive' => ''
            ],
            'encrypted' => [
                'VAL1' => 'encrypted1',
                'VAL2' => 'encrypted2'
            ]
        ];
        $this->decrypter
            ->shouldReceive('decryptProperties')
            ->andReturn(['derp', 'derp2']);
        $this->decrypter
            ->shouldReceive('mergePropertiesIntoEnv')
            ->andReturn([
                'derp' => 'herp',
                'HAL_ENVIRONMENT' => 'test',
                'VAL1' => 'unencrypted1',
                'VAL2' => 'unencrypted2'
            ]);
        $expectedEnv = [
            'derp' => 'herp',
            'HAL_ENVIRONMENT' => 'test',
            'VAL1' => 'unencrypted1',
            'VAL2' => 'unencrypted2',
            'GLOBAL_DERP' => '1234',
            'TEST_DERP' => '8765',
            'OVERRIDDEN' => '4321',
        ];
        $this->exporter
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->builder
            ->shouldReceive('__invoke')
            ->with(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), $expectedEnv)
            ->andReturn(true)
            ->once();
        $this->importer
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $handler = new UnixBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter,
            'default-image'
        );
        $handler->disableShutdownHandler();
        $actual = $handler($properties['configuration']['build'], $properties);
        $this->assertSame(0, $actual);
    }
}
