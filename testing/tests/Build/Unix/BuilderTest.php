<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Unix;

use Mockery;
use PHPUnit_Framework_TestCase;

class BuilderTest extends PHPUnit_Framework_TestCase
{
    public $preparer;
    public $logger;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
    }

    public function testSuccess()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getOutput' => 'test-output',
            'getCommandLine' => 'deployscript',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), [
                'command' => 'deployscript',
                'output' => 'test-output'
            ])->once();

        $action = new Builder($this->logger, $builder, 5);

        $success = $action('path', ['command'], []);
        $this->assertTrue($success);
    }

    public function testFail()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'getCommandLine' => 'deployscript',
            'isSuccessful' => false,
            'getExitCode' => 127
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => 'deployscript',
                'exitCode' => 127,
                'output' => 'test-output',
                'errorOutput' => 'test-error-output'
            ])->once();

        $action = new Builder($this->logger, $builder, 5);

        $success = $action('path', ['command'], []);
        $this->assertFalse($success);
    }

    public function testBuilderIsParameterizedCorrectly()
    {
        $command = "bin/deploy --production && env > text.txt && bin/cmd 0  false         end\nweird\t";
        $expectedParameters = [
            'bin/deploy',
            '--production',
            '&&',
            'env',
            '>',
            'text.txt',
            '&&',
            'bin/cmd',
            '0',
            'false',
            "end\nweird\t"
        ];

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getCommandLine' => null,
            'getOutput' => null,
            'isSuccessful' => true,
            'stop' => null
        ]);

        $actualParameters = null;
        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');
        $builder
            ->shouldReceive('setWorkingDirectory')
            ->andReturn(Mockery::self());
        $builder
            ->shouldReceive('setArguments')
            ->with(Mockery::on(function($v) use (&$actualParameters) {
                $actualParameters = $v;
                return true;
            }))
            ->andReturn(Mockery::self());
        $builder
            ->shouldReceive('addEnvironmentVariables')
            ->andReturn(Mockery::self());
        $builder
            ->shouldReceive('setTimeout')
            ->with(5)
            ->andReturn(Mockery::self());
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->once();

        $action = new Builder($this->logger, $builder, 5);
        $success = $action('path', [$command], []);

        $this->assertSame($expectedParameters, $actualParameters);
    }

    public function testMultipleCommandsAreRun()
    {
        $commands = [
            'command1',
            'command2'
        ];

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getCommandLine' => null,
            'getOutput' => null,
            'isSuccessful' => true,
            'stop' => null
        ]);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');
        $builder
            ->shouldReceive('setWorkingDirectory')
            ->andReturn(Mockery::self());
        $builder
            ->shouldReceive('setArguments')
            ->andReturn(Mockery::self());
        $builder
            ->shouldReceive('addEnvironmentVariables')
            ->andReturn(Mockery::self());
        $builder
            ->shouldReceive('setTimeout')
            ->andReturn(Mockery::self());
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process)
            ->twice();

        $this->logger
            ->shouldReceive('event')
            ->twice();

        $action = new Builder($this->logger, $builder, 5);
        $success = $action('path', $commands, []);

        $this->assertSame(true, $success);
    }
}
