<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Windows;

use Mockery;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class WindowsBuildHandlerTest extends PHPUnit_Framework_TestCase
{
    public $output;
    public $logger;

    public $preparer;
    public $exporter;
    public $builder;
    public $importer;
    public $cleaner;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');

        $this->preparer = Mockery::mock('QL\Hal\Agent\Build\PackageManagerPreparer', ['__invoke' => true]);
        $this->exporter = Mockery::mock('QL\Hal\Agent\Build\Windows\Exporter', ['__invoke' => true]);
        $this->builder = Mockery::mock('QL\Hal\Agent\Build\Windows\Builder', ['__invoke' => true]);
        $this->importer = Mockery::mock('QL\Hal\Agent\Build\Windows\Importer', ['__invoke' => true]);
        $this->cleaner = Mockery::mock('QL\Hal\Agent\Build\Windows\Cleaner', ['__invoke' => true]);
    }

    public function testSuccess()
    {
        $properties = [
            'windows' => [
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

        $this->logger
            ->shouldReceive('setStage')
            ->with('building')
            ->once();

        // $this->preparer
        //     ->shouldReceive('__invoke')
        //     ->andReturn(true)
        //     ->once();
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
            $this->preparer,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner
        );
        $handler->disableShutdownHandler();

        $actual = $handler($this->output, $properties);

        $this->assertSame(0, $actual);

        $expected = <<<'OUTPUT'
Building on windows
Exporting files to build server
Preparing package manager configuration
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
                'system' => 'windows',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
            ]
        ];

        $handler = new WindowsBuildHandler(
            $this->logger,
            $this->preparer,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner
        );
        $handler->disableShutdownHandler();

        $actual = $handler($this->output, $properties);
        $this->assertSame(200, $actual);
    }

    public function testFailOnBuild()
    {
        $properties = [
            'windows' => [
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

        $this->logger
            ->shouldReceive('setStage')
            ->with('building')
            ->once();

        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(false)
            ->once();

        $handler = new WindowsBuildHandler(
            $this->logger,
            $this->preparer,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner
        );
        $handler->disableShutdownHandler();

        $actual = $handler($this->output, $properties);
        $this->assertSame(203, $actual);
    }
}
