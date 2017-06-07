<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Windows;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class WindowsBuildHandlerTest extends MockeryTestCase
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

        $this->exporter = Mockery::mock('Hal\Agent\Build\Windows\Exporter', ['__invoke' => true]);
        $this->builder = Mockery::mock('Hal\Agent\Build\Windows\Builder', ['__invoke' => true]);
        $this->importer = Mockery::mock('Hal\Agent\Build\Windows\Importer', ['__invoke' => true]);
        $this->cleaner = Mockery::mock('Hal\Agent\Build\Windows\Cleaner', ['__invoke' => true]);
        $this->decrypter = Mockery::mock('Hal\Agent\Utility\EncryptedPropertyResolver');
    }

    public function testSuccess()
    {
        $properties = [
            'windows' => [
                'buildUser' => 'sshuser',
                'buildServer' => 'windowsserver',
                'remotePath' => '/path',
                'environmentVariables' => []
            ],
            'configuration' => [
                'system' => 'windows',
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

        $this->importer
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();

        // non-essential commands
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();

        $handler = new WindowsBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter
        );
        $handler->disableShutdownHandler();
        $handler->setOutput($this->output);

        $actual = $handler($properties['configuration']['build'], $properties);

        $this->assertSame(0, $actual);

        $expected = <<<'OUTPUT'
[Building - Windows] Building on windows
[Building - Windows] Validating windows configuration
[Building - Windows] Exporting files to build server
[Building - Windows] Running build command
[Building - Windows] Importing files from build server
[Shutdown] Cleaning up remote windows build server

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testFailSanityCheck()
    {
        $properties = [
            'configuration' => [
                'system' => 'windows',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
            ]
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Windows build system is not configured')
            ->once();

        $handler = new WindowsBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter
        );
        $handler->disableShutdownHandler();

        $actual = $handler($properties['configuration']['build'], $properties);

        $this->assertSame(200, $actual);
    }

    public function testFailOnBuild()
    {
        $properties = [
            'windows' => [
                'buildUser' => 'sshuser',
                'buildServer' => 'windowsserver',
                'remotePath' => '/path',
                'environmentVariables' => []
            ],
            'configuration' => [
                'system' => 'windows',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
            ]
        ];

        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(false)
            ->once();

        $handler = new WindowsBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter
        );
        $handler->disableShutdownHandler();

        $actual = $handler($properties['configuration']['build'], $properties);
        $this->assertSame(203, $actual);
    }

    public function testBadDecryptHaltsBuild()
    {
        $properties = [
            'windows' => [
                'buildUser' => 'sshuser',
                'buildServer' => 'windowsserver',
                'remotePath' => '/path',
                'environmentVariables' => ['derp' => 'herp']
            ],
            'configuration' => [
                'system' => 'windows',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
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
            ->with('failure', WindowsBuildHandler::ERR_BAD_DECRYPT)
            ->once();

        $this->builder
            ->shouldReceive('__invoke')
            ->never();

        $handler = new WindowsBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter
        );
        $handler->disableShutdownHandler();

        $actual = $handler($properties['configuration']['build'], $properties);
        $this->assertSame(202, $actual);
    }

    public function testEncryptedPropertiesMergedIntoEnv()
    {
        $properties = [
            'windows' => [
                'buildUser' => 'sshuser',
                'buildServer' => 'windowsserver',
                'remotePath' => '/path',
                'environmentVariables' => ['derp' => 'herp']
            ],
            'configuration' => [
                'system' => 'windows',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
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

        $this->builder
            ->shouldReceive('__invoke')
            ->with(
                'sshuser',
                'windowsserver',
                '/path',
                ['cmd1'],
                ['decrypt-output']
            )
            ->andReturn(true)
            ->once();

        $handler = new WindowsBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter
        );
        $handler->disableShutdownHandler();

        $actual = $handler($properties['configuration']['build'], $properties);

        $this->assertSame(0, $actual);
    }
}
