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

    public $preparer;
    public $builder;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');

        $this->preparer = Mockery::mock('QL\Hal\Agent\Build\PackageManagerPreparer', ['__invoke' => null]);
        $this->builder = Mockery::mock('QL\Hal\Agent\Build\Unix\Builder', ['__invoke' => null]);
    }

    public function testSuccess()
    {
        $properties = [
            'unix' => [
                'environmentVariables' => []
            ],
            'configuration' => [
                'system' => 'unix',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
            ]
        ];

        $this->preparer
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();

        // non-essential commands
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();

        $handler = new UnixBuildHandler($this->logger, $this->preparer, $this->builder);

        $actual = $handler($this->output, $properties['configuration']['build'], $properties);

        $this->assertSame(0, $actual);

        $expected = <<<'OUTPUT'
Building on unix
Preparing package manager configuration
Running build command

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testFailSanityCheck()
    {
        $properties = [
            'configuration' => [
                'system' => 'unix',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
            ]
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Unix build system is not configured')
            ->once();

        $handler = new UnixBuildHandler($this->logger, $this->preparer, $this->builder);

        $actual = $handler($this->output, $properties['configuration']['build'], $properties);
        $this->assertSame(100, $actual);
    }

    public function testFailOnBuild()
    {
        $properties = [
            'unix' => [
                'environmentVariables' => []
            ],
            'configuration' => [
                'system' => 'unix',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
            ]
        ];

        $this->preparer
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(false)
            ->once();

        $handler = new UnixBuildHandler($this->logger, $this->preparer, $this->builder);
        $actual = $handler($this->output, $properties['configuration']['build'], $properties);
        $this->assertSame(102, $actual);
    }

}
