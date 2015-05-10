<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Unix;

use Mockery;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class UnixBuildHandlerTest extends PHPUnit_Framework_TestCase
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
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');

        $this->exporter = Mockery::mock('QL\Hal\Agent\Build\Unix\Exporter', ['__invoke' => null]);
        $this->builder = Mockery::mock('QL\Hal\Agent\Build\Unix\DockerBuilder', [
            '__invoke' => null,
            '__destruct' => null,
            'cleanup' => null
        ]);
        $this->importer = Mockery::mock('QL\Hal\Agent\Build\Unix\Importer', ['__invoke' => null]);
        $this->cleaner = Mockery::mock('QL\Hal\Agent\Build\Unix\Cleaner', ['__invoke' => null]);
        $this->decrypter = Mockery::mock('QL\Hal\Agent\Utility\EncryptedPropertyResolver');
    }

    public function testSuccess()
    {
        $properties = [
            'unix' => [
                'environmentVariables' => [],
                'buildUser' => 'testuser',
                'buildServer' => 'buildserver',
                'remotePath' => '/tmp/builds/derp'
            ],
            'configuration' => [
                'system' => 'unix',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
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
Building on unix
Validating unix configuration
Exporting files to build server
Running build command
Importing files from build server
Cleaning up build server

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
                'remotePath' => '/tmp/builds/derp'
            ],
            'location' => [
                'path' => ''
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
                'remotePath' => '/tmp/builds/derp'
            ],
            'configuration' => [
                'system' => 'docker:custom-docker-image',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
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
                '/tmp/builds/derp',
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
                'remotePath' => '/tmp/builds/derp'
            ],
            'configuration' => [
                'system' => 'unix',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => '/path'
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
                'remotePath' => '/tmp/builds/derp'
            ],
            'configuration' => [
                'system' => 'unix',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => '/path'
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
                '/tmp/builds/derp',
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
}
