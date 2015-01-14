<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use Mockery;
use PHPUnit_Framework_TestCase;

class PackerTest extends PHPUnit_Framework_TestCase
{
    public $file;
    public $logger;

    public function setUp()
    {
        $this->file = FIXTURES_DIR . '/push.zip';
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
    }

    public function testFailCommand()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getCommandLine' => 'deployscript',
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'isSuccessful' => false
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $packer = new Packer($this->logger, $builder, 5);
        $success = $packer('path', $this->file);
        $this->assertSame(false, $success);
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
            ->with('success', Mockery::any(), Mockery::any())
            ->once();

        $packer = new Packer($this->logger, $builder, 5);
        $success = $packer('path', $this->file);
        $this->assertSame(true, $success);
    }

}
