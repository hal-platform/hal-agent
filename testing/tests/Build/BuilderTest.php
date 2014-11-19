<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;

class BuilderTest extends PHPUnit_Framework_TestCase
{
    public $preparer;
    public $logger;

    public function setUp()
    {
        $this->preparer = Mockery::mock('QL\Hal\Agent\Build\PackageManagerPreparer', ['__invoke' => null]);
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
            ->shouldReceive('success')
            ->with(Mockery::any(), [
                'command' => 'deployscript',
                'output' => 'test-output'
            ])->once();

        $action = new Builder($this->logger, $builder, $this->preparer, 5);

        $success = $action('path', 'command', []);
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
            ->shouldReceive('failure')
            ->with(Mockery::any(), [
                'command' => 'deployscript',
                'exitCode' => 127,
                'output' => 'test-output',
                'errorOutput' => 'test-error-output'
            ])->once();

        $action = new Builder($this->logger, $builder, $this->preparer, 5);

        $success = $action('path', 'command', []);
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
            ->shouldReceive('success')
            ->once();

        $action = new Builder($this->logger, $builder, $this->preparer, 5);
        $success = $action('path', $command, []);

        $this->assertSame($expectedParameters, $actualParameters);
    }
}
