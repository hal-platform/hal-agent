<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\MemoryLogger;

class BuilderTest extends PHPUnit_Framework_TestCase
{
    public function testSuccess()
    {
        $logger = new MemoryLogger;
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Builder($logger, $builder, 5);

        $success = $action('path', 'command', []);
        $this->assertTrue($success);

        $message = $logger[0];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Build command executed', $message[1]);
        $this->assertSame('test-output', $message[2]['output']);
    }

    public function testFail()
    {
        $logger = new MemoryLogger;
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'isSuccessful' => false,
            'getExitCode' => 127
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Builder($logger, $builder, 5);

        $success = $action('path', 'command', []);
        $this->assertFalse($success);

        $message = $logger[0];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('Build command executed with errors', $message[1]);
        $this->assertSame('test-output', $message[2]['output']);
        $this->assertSame(127, $message[2]['exitCode']);
        $this->assertSame('test-error-output', $message[2]['errorOutput']);
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

        $logger = new MemoryLogger;
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
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

        $action = new Builder($logger, $builder, 5);
        $success = $action('path', $command, []);

        $this->assertSame($expectedParameters, $actualParameters);
    }
}
