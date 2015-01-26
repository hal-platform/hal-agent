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

class BuilderTest extends PHPUnit_Framework_TestCase
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
        $this->builder = Mockery::mock('QL\Hal\Agent\Build\Windows\BuildCommand', ['__invoke' => null]);
    }

    public function testSuccess()
    {
        $properties = [
            'windows' => [],
            'configuration' => [
                'system' => 'windows',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
            ],
            'environmentVariables' => []
        ];

        $this->logger
            ->shouldReceive('setStage')
            ->with('building')
            ->once();

        $this->preparer
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();

        // non-essential commands
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();

        $builder = new Builder($this->logger, $this->preparer, $this->builder);
        $actual = $builder($this->output, $properties);

        $this->assertSame(0, $actual);

        $expected = <<<'OUTPUT'
Building on windows
Preparing package manager configuration
Running build command

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
            ],
            'environmentVariables' => []
        ];

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');

        $builder = new Builder($this->logger, $this->preparer, $this->builder);
        $actual = $builder($this->output, $properties);
        $this->assertSame(200, $actual);
    }

    public function testFailOnBuild()
    {
        $properties = [
            'windows' => [],
            'configuration' => [
                'system' => 'windows',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
            ],
            'environmentVariables' => []
        ];

        $this->logger
            ->shouldReceive('setStage')
            ->with('building')
            ->once();

        $this->preparer
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(false)
            ->once();

        $builder = new Builder($this->logger, $this->preparer, $this->builder);
        $actual = $builder($this->output, $properties);
        $this->assertSame(202, $actual);
    }
}
