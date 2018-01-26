<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS\AWS;

use Aws\Result;
use Aws\Ssm\SsmClient;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;
use Hal\Agent\Waiter\Waiter;
use Hal\Agent\Logger\EventLogger;

class SSMCommandRunnerTest extends MockeryTestCase
{
    public $logger;
    public $waiter;
    public $ssm;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->ssm = Mockery::mock(SsmClient::class);

        $this->waiter = new Waiter(.25, 10);
    }

    public function testSuccess()
    {
        $this->ssm
            ->shouldReceive('sendCommand')
            ->with([
                'InstanceIds' => ['i-1234'],
                'DocumentName' => 'AWS-RunShell',
                'TimeoutSeconds' => '30',
                'Parameters' => [
                    'command' => ['param1', 'param2'],
                    'other_option' => '1234'
                ]
            ])
            ->andReturn(new Result(['Command' => ['CommandId' => 'a-123456']]))
            ->once();

        $this->ssm
            ->shouldReceive('getCommandInvocation')
            ->with([
                'InstanceId' => 'i-1234',
                'CommandId' => 'a-123456'
            ])
            ->andReturn(new Result(['Status' => 'InProgress']))
            ->once();
        $this->ssm
            ->shouldReceive('getCommandInvocation')
            ->with([
                'InstanceId' => 'i-1234',
                'CommandId' => 'a-123456'
            ])
            ->andReturn(new Result([
                'Status' => 'Success',
                'ResponseCode' => '0',
                'StandardOutputContent' => 'stdout test',
                'StandardErrorContent' => ''
            ]))
            ->twice();

        $runner = new SSMCommandRunner($this->logger, $this->waiter);
        $runner->setMandatoryWaitPeriod(false);

        $result = $runner($this->ssm, 'i-1234', 'AWS-RunShell', [
            'command' => ['param1', 'param2'],
            'other_option' => '1234'
        ]);

        $this->assertSame(true, $result);
    }

    public function testCommandTimesOutWaiting()
    {
        $this->ssm
            ->shouldReceive('sendCommand')
            ->with([
                'InstanceIds' => ['i-1234'],
                'DocumentName' => 'AWS-RunShell',
                'TimeoutSeconds' => '30',
                'Parameters' => []
            ])
            ->andReturn(new Result(['Command' => ['CommandId' => 'a-123456']]))
            ->once();

        $this->ssm
            ->shouldReceive('getCommandInvocation')
            ->with([
                'InstanceId' => 'i-1234',
                'CommandId' => 'a-123456'
            ])
            ->andReturn(new Result(['Status' => 'InProgress']))
            ->times(3);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Waited for command to finish, but the operation timed out.', Mockery::any())
            ->once();

        $waiter = new Waiter(.25, 3);

        $runner = new SSMCommandRunner($this->logger, $waiter);
        $runner->setMandatoryWaitPeriod(false);

        $result = $runner($this->ssm, 'i-1234', 'AWS-RunShell', []);

        $this->assertSame(false, $result);
    }
}
