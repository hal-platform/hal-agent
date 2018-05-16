<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Docker;

use Aws\Ssm\SsmClient;
use Hal\Agent\Build\WindowsAWS\AWS\SSMCommandRunner;
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;

class WindowsSSMDockerinatorTest extends MockeryTestCase
{
    public $logger;
    public $ssm;
    public $runner;
    public $powershell;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->ssm = Mockery::mock(SsmClient::class);
        $this->runner = Mockery::mock(SSMCommandRunner::class);
        $this->powershell = new Powershellinator('c:\builds', 'c:\build-scripts', 'c:\tools');
    }

    public function testCreateContainer()
    {
        [$withSpy, $spy] = $this->spy();
        $this->runner
            ->shouldReceive('__invoke')
            ->with($this->ssm, 'i-1234', 'AWS-RunPowerShellScript', $withSpy, [false, 'Create Docker container'])
            ->andReturn(true);

        $dockerinator = new WindowsSSMDockerinator(
            $this->logger,
            $this->runner,
            $this->powershell,
            '{"customhost1": "127.0.0.1", "customhost2": "192.168.0.1"}'
        );
        $result = $dockerinator->createContainer($this->ssm, 'i-1234', 'microsoft/windowsservercore', 'random_container1', [
            'env1' => 'derp'
        ]);

        $expectedCommand = <<<'POWERSHELL'
docker create --tty=true --interactive=true --name "random_container1" --workdir="c:\workspace" microsoft/windowsservercore powershell
POWERSHELL;

        $params = $spy();

        $this->assertSame(true, $result);
        $this->assertSame(true, is_array($params));
        $this->assertSame($expectedCommand, $params['commands'][1]);
    }

    public function testCopyInContainer()
    {
        [$withSpy, $spy] = $this->spy();
        $this->runner
            ->shouldReceive('__invoke')
            ->with($this->ssm, 'i-1234', 'AWS-RunPowerShellScript', $withSpy, [false, 'Copy source into container'])
            ->andReturn(true);

        $dockerinator = new WindowsSSMDockerinator($this->logger, $this->runner, $this->powershell);
        $result = $dockerinator->copyIntoContainer($this->ssm, 'i-1234', 'build_123', 'random_container1', 'c:\build\temp1234');

        $expectedCommand1 = <<<'POWERSHELL'
docker cp c:\build\temp1234\. random_container1:c:\workspace
POWERSHELL;

        $expectedCommand2 = <<<'POWERSHELL'
docker cp c:\build-scripts\build_123\. random_container1:c:\build-scripts
POWERSHELL;

        $params = $spy();

        $this->assertSame(true, $result);
        $this->assertSame(true, is_array($params));
        $this->assertSame($expectedCommand1, $params['commands'][1]);
        $this->assertSame($expectedCommand2, $params['commands'][2]);
    }

    public function testCopyFromContainer()
    {
        [$withSpy, $spy] = $this->spy();

        $this->runner
            ->shouldReceive('__invoke')
            ->with($this->ssm, 'i-1234', 'AWS-RunPowerShellScript', $withSpy, [false, 'Copy artifacts from container'])
            ->andReturn(true);

        $dockerinator = new WindowsSSMDockerinator($this->logger, $this->runner, $this->powershell);
        $result = $dockerinator->copyFromContainer($this->ssm, 'i-1234', 'random_container1', 'c:\build\output1234');

        $expectedCommand = <<<'POWERSHELL'
docker cp random_container1:c:\workspace c:\build\output1234
POWERSHELL;

        $params = $spy();

        $this->assertSame(true, $result);
        $this->assertSame(true, is_array($params));
        $this->assertSame($expectedCommand, $params['commands'][1]);
    }

    public function testRunCommand()
    {
        $commandParsed = [
            'command' => 'ls -hal',
            'script' => 'safe header ; ls -hal',
            'container_file' => 'c:\build-scripts\script_cmd2.ps1'
        ];

        [$withSpy, $spy] = $this->spy();

        $this->runner
            ->shouldReceive('__invoke')
            ->with($this->ssm, 'i-1234', 'AWS-RunPowerShellScript', $withSpy, [
                true,
                'custom message',
                [
                    'command' => 'ls -hal',
                    'script' => 'safe header ; ls -hal',
                    'scriptFile' => 'c:\build-scripts\script_cmd2.ps1'
                ]
            ])
            ->andReturn(true);

        $this->runner
            ->shouldReceive('getLastStatus')
            ->andReturn([
                'errorOutput' => ''
            ]);

        $dockerinator = new WindowsSSMDockerinator($this->logger, $this->runner, $this->powershell);
        $result = $dockerinator->runCommand($this->ssm, 'i-1234', 'container_name', $commandParsed, 'custom message');

        $expectedCommand = <<<'POWERSHELL'
docker exec "container_name" powershell -NonInteractive -NoProfile -InputFormat None -OutputFormat Text -ExecutionPolicy Unrestricted -File c:\build-scripts\script_cmd2.ps1 ; Exit $LastExitCode
POWERSHELL;

        $params = $spy();

        $this->assertSame(true, $result);
        $this->assertSame(true, is_array($params));
        $this->assertSame($expectedCommand, $params['commands'][0]);
        $this->assertSame('1800', $params['executionTimeout'][0]);
    }

}
