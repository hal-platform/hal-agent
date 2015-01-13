<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Mockery;
use PHPUnit_Framework_TestCase;

class BuilderTest extends PHPUnit_Framework_TestCase
{
    public $logger;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
    }

    public function testSuccess()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getCommandLine' => 'deployscript',
            'getOutput' => 'test-output',
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

        $success = $action('global', 'path', ['command'], []);
        $this->assertTrue($success);
    }

    public function testFail()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getCommandLine' => 'deployscript',
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'getExitCode' => 127,
            'isSuccessful' => false
        ])->makePartial();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => 'deployscript',
                'output' => 'test-output',
                'errorOutput' => 'test-error-output',
                'exitCode' => 127
            ])->once();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Builder($this->logger, $builder, 5);

        $success = $action('global', 'path', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailIfUnknownSystem()
    {
        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Invalid build environment specified')
            ->once();

        $action = new Builder($this->logger, $builder, 5);

        $success = $action('bad_system', 'path', ['command'], []);
        $this->assertFalse($success);
    }

    public function testBuildCommandIsParameterizedCorrectly()
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
            'getCommandLine' => 'deployscript',
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'getExitCode' => 127,
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
            ->shouldReceive('event');

        $action = new Builder($this->logger, $builder, 5);
        $success = $action('global', 'path', [$command], []);

        $this->assertSame($expectedParameters, $actualParameters);
    }

    public function testBuildMultipleCommands()
    {
        $commands = [
            'derp1',
            'derp2'
        ];

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getCommandLine' => 'deployscript',
            'getOutput' => 'test-output',
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
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->twice();

        $action = new Builder($this->logger, $builder, 5);
        $success = $action('global', 'path', $commands, []);

        $this->assertSame($success, true);
    }
}
