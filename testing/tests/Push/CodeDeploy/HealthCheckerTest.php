<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\CodeDeploy;

use Aws\CommandInterface;
use Aws\CodeDeploy\CodeDeployClient;
use Aws\CodeDeploy\Exception\CodeDeployException;
use Aws\Result;
use Mockery;
use PHPUnit_Framework_TestCase;

use DateTime;
use QL\MCP\Common\Time\Clock;
use QL\MCP\Common\Time\TimePoint;

class HealthCheckerTest extends PHPUnit_Framework_TestCase
{
    public $cd;
    public $clock;

    public function setUp()
    {
        $this->cd = Mockery::mock(CodeDeployClient::class);
        $this->clock = new Clock;
    }

    public function testRecentDeploymentRevisionHealth()
    {
        $deploymentsResult = new Result([
            'deployments' => [
                '1234',
                '5678'
            ],
            'nextToken' => 'dontcare',
        ]);

        $infoResult = new Result([
            'deploymentInfo' => [
                'applicationName' => 'appName',
                'deploymentGroupName' => 'groupName',
                'deploymentId' => '1234',
                'deploymentOverview' => [
                    'Failed' => 0,
                    'InProgress' => 0,
                    'Pending' => 0,
                    'Skipped' => 0,
                    'Succeeded' => 5,
                ],

                'status' => 'Succeeded',
            ]
        ]);

        $this->cd
            ->shouldReceive('listDeployments')
            ->with([
                'applicationName' => 'appName',
                'deploymentGroupName' => 'groupName'
            ])
            ->andReturn($deploymentsResult);
        $this->cd
            ->shouldReceive('getDeployment')
            ->with([
                'deploymentId' => '1234',
            ])
            ->andReturn($infoResult);

        $checker = new HealthChecker($this->clock, 'America/Detroit');
        $actual = $checker($this->cd, 'appName', 'groupName');

        $expectedOverview = [
            'Failed' => 0,
            'InProgress' => 0,
            'Pending' => 0,
            'Skipped' => 0,
            'Succeeded' => 5
        ];

        $this->assertSame('Succeeded', $actual['status']);
        $this->assertSame($expectedOverview, $actual['overview']);
        $this->assertSame(null, $actual['error']);
    }

    public function testNoDeploymentsReturnsAsNonstandardNone()
    {
        $deploymentsResult = new Result([
            'deployments' => [],
            'nextToken' => 'dontcare',
        ]);

        $this->cd
            ->shouldReceive('listDeployments')
            ->with([
                'applicationName' => 'appName',
                'deploymentGroupName' => 'groupName'
            ])
            ->andReturn($deploymentsResult);

        $checker = new HealthChecker($this->clock, 'America/Detroit');
        $actual = $checker($this->cd, 'appName', 'groupName');

        $this->assertSame('None', $actual['status']);
        $this->assertSame(null, $actual['overview']);
        $this->assertSame(null, $actual['error']);
    }

    public function testClientErrorReturnsInvalid()
    {
        $ex = new CodeDeployException('msg', Mockery::mock(CommandInterface::class));
        $this->cd
            ->shouldReceive('listDeployments')
            ->andThrow($ex);

        $checker = new HealthChecker($this->clock, 'America/Detroit');
        $actual = $checker($this->cd, 'appName', 'groupName');

        $this->assertSame('Invalid', $actual['status']);
        $this->assertSame(null, $actual['overview']);
        $this->assertSame(null, $actual['error']);
    }

    public function testGetDeploymentHealth()
    {
        $infoResult = new Result([
            'deploymentInfo' => [
                'applicationName' => 'appName',
                'deploymentGroupName' => 'groupName',
                'deploymentId' => '1234',
                'deploymentOverview' => [
                    'Failed' => 0,
                    'InProgress' => 0,
                    'Pending' => 0,
                    'Skipped' => 0,
                    'Succeeded' => 5,
                ],

                'status' => 'Succeeded',
            ]
        ]);

        $this->cd
            ->shouldReceive('getDeployment')
            ->with(['deploymentId' => '1234'])
            ->andReturn($infoResult);

        $checker = new HealthChecker($this->clock, 'America/Detroit');
        $actual = $checker->getDeploymentHealth($this->cd, '1234');

        $expectedOverview = [
            'Failed' => 0,
            'InProgress' => 0,
            'Pending' => 0,
            'Skipped' => 0,
            'Succeeded' => 5
        ];

        $this->assertSame('Succeeded', $actual['status']);
        $this->assertSame($expectedOverview, $actual['overview']);
        $this->assertSame(null, $actual['error']);
    }

    public function testInstanceHealthDoesNotGetInstanceHealthIfDeploymentPending()
    {
        $infoResult = new Result([
            'deploymentInfo' => [
                'applicationName' => 'appName',
                'deploymentGroupName' => 'groupName',
                'deploymentId' => '1234',
                'deploymentOverview' => [],

                'status' => 'Pending',
            ]
        ]);

        $this->cd
            ->shouldReceive('getDeployment')
            ->with(['deploymentId' => '1234'])
            ->andReturn($infoResult);
        $this->cd
            ->shouldReceive('listDeploymentInstances')
            ->never();

        $checker = new HealthChecker($this->clock, 'America/Detroit');
        $actual = $checker->getDeploymentInstancesHealth($this->cd, '1234');

        $this->assertSame('Pending', $actual['status']);
    }

    public function testGetInstanceHealthWithNoInstances()
    {
        $infoResult = new Result([
            'deploymentInfo' => [
                'applicationName' => 'appName',
                'deploymentGroupName' => 'groupName',
                'deploymentId' => '1234',
                'deploymentOverview' => [],

                'status' => 'Succeeded',
            ]
        ]);

        $instancesResult = new Result([
            'instancesList' => []
        ]);

        $this->cd
            ->shouldReceive('getDeployment')
            ->with(['deploymentId' => '1234'])
            ->andReturn($infoResult);
        $this->cd
            ->shouldReceive('listDeploymentInstances')
            ->with(['deploymentId' => '1234'])
            ->andReturn($instancesResult);

        $checker = new HealthChecker($this->clock, 'America/Detroit');
        $actual = $checker->getDeploymentInstancesHealth($this->cd, '1234');

        $this->assertSame('Succeeded', $actual['status']);

        // Instances logs not returned
        $this->assertArrayNotHasKey('instances', $actual);
        $this->assertArrayNotHasKey('instancesSummary', $actual);
    }

    public function testGetInstanceHealth()
    {
        $infoResult = new Result([
            'deploymentInfo' => [
                'applicationName' => 'appName',
                'deploymentGroupName' => 'groupName',
                'deploymentId' => '1234',
                'deploymentOverview' => [],

                'status' => 'Succeeded',
            ]
        ]);

        $instancesResult = new Result([
            'instancesList' => ['abcd', 'efgh', 'ijkl']
        ]);

        $instancesInfoResult = new Result([
            'errorMessage' => '',
            'instancesSummary' => [
                [
                    'instanceId' => 'abcd',
                    'lastUpdatedAt' => '2017-01-13T19:07:09+00:00',
                    'status' => 'Failed', // Pending|InProgress|Succeeded||Skipped|Unknown
                    'lifecycleEvents' => [
                        [
                            'diagnostics' => [
                                'errorCode' => 'ScriptMissing', // Success|ScriptMissing|ScriptNotExecutable|ScriptTimedOut|ScriptFailed|UnknownError
                                'logTail' => '',
                                'message' => 'Script is missing from revision.',
                                'scriptName' => 'install-script.sh',
                            ],
                            'startTime' => '2017-01-13T17:45:09+00:00',
                            'endTime' => '2017-01-13T19:03:56+00:00',
                            'lifecycleEventName' => 'Install',
                            'status' => 'Failed' // Pending|InProgress|Succeeded|Failed|Skipped|Unknown
                        ],
                        [
                            'diagnostics' => [
                                'errorCode' => 'ScriptTimedOut',
                                'logTail' => "LifecycleEvent - ValidateService\nScript - scripts/test_script\n[stdout]Starting script and restarting service.\n",
                                'message' => 'Script at specified location: scripts/test_script failed to complete in 30 seconds',
                                'scriptName' => 'scripts/test_script',
                            ],
                            'startTime' => '2017-01-13T19:07:09+00:00',
                            // 'endTime' => '',
                            'lifecycleEventName' => 'ValidateService',
                            'status' => 'Failed'
                        ],
                    ]
                ],
                [
                    'instanceId' => 'efgh',
                    'lastUpdatedAt' => '2017-01-13T19:06:44+00:00',
                    'status' => 'Failed',
                    'lifecycleEvents' => [
                        [
                            'startTime' => '2017-01-13T18:49:42+00:00',
                            'endTime' => '2017-01-13T18:46:10+00:00',
                            'lifecycleEventName' => 'ApplicationStop',
                            'status' => 'Succeeded'
                        ],
                        [
                            'startTime' => '2017-01-13T18:53:21+00:00',
                            'endTime' => '2017-01-13T18:53:21+00:00',
                            'lifecycleEventName' => 'DownloadBundle',
                            'status' => 'Succeeded'
                        ],
                        [
                            'diagnostics' => [
                                'errorCode' => 'ScriptFailed',
                                'logTail' => '[stdout] test',
                                'message' => 'Something failed.',
                                'scriptName' => 'scripts/my-install.sh',
                            ],
                            'startTime' => '2017-01-13T18:57:21+00:00',
                            'endTime' => '2017-01-13T19:06:44+00:00',
                            'lifecycleEventName' => 'Install',
                            'status' => 'Failed'
                        ],
                    ]
                ],
                [
                    'instanceId' => 'ijkl',
                    'lastUpdatedAt' => '2017-01-13T19:07:09+00:00',
                    'status' => 'Skipped',
                    'lifecycleEvents' => [
                        ['lifecycleEventName' => 'ApplicationStop', 'status' => 'Skipped'],
                        ['lifecycleEventName' => 'DownloadBundle', 'status' => 'Skipped'],
                        ['lifecycleEventName' => 'BeforeInstall', 'status' => 'Skipped'],
                        ['lifecycleEventName' => 'Install', 'status' => 'Skipped'],
                    ]
                ]
            ]
        ]);

        $this->cd
            ->shouldReceive('getDeployment')
            ->with(['deploymentId' => '1234'])
            ->andReturn($infoResult);
        $this->cd
            ->shouldReceive('listDeploymentInstances')
            ->with(['deploymentId' => '1234'])
            ->andReturn($instancesResult);
        $this->cd
            ->shouldReceive('batchGetDeploymentInstances')
            ->with([
                'deploymentId' => '1234',
                'instanceIds' => ['abcd', 'efgh', 'ijkl']
            ])
            ->andReturn($instancesInfoResult);

        $expectedInstanceSummary = <<<'TEXT'
Instance ID          | Type                 | Status          | Start Time                     | End Time                       | Duration             | Most Recent Event   
-------------------- | -------------------- | --------------- | ------------------------------ | ------------------------------ | -------------------- | --------------------
abcd                 | Original             | Failed          | Jan 13, 2017 12:45:09 EST      | N/A                            |                      | ValidateService     
efgh                 | Original             | Failed          | Jan 13, 2017 01:49:42 EST      | Jan 13, 2017 02:06:44 EST      | 17 min, 2 sec        | Install             
ijkl                 | Original             | Skipped         | Jan 13, 2017 02:07:09 EST      | N/A                            |                      |                     
TEXT;
        $expectedInstanceDetailed = <<<'TEXT'
>>>> Instance ID: abcd
>>>> Status: Failed (Type: Original)
>>>> Last Update: Jan 13, 2017 02:07:09 EST

Event Name           | Status               | Start                          | End                            | Duration            
-------------------- | -------------------- | ------------------------------ | ------------------------------ | --------------------
Install              | Failed               | Jan 13, 2017 12:45:09 EST      | Jan 13, 2017 02:03:56 EST      | 1 hr, 18 min        
ValidateService      | Failed               | Jan 13, 2017 02:07:09 EST      | N/A                            |                     

Install event failed! Script is missing from revision.

Script: install-script.sh
Error Code: ScriptMissing



ValidateService event failed! Script at specified location: scripts/test_script failed to complete in 30 seconds

Script: scripts/test_script
Error Code: ScriptTimedOut

LifecycleEvent - ValidateService
Script - scripts/test_script
[stdout]Starting script and restarting service.


>>>> Instance ID: efgh
>>>> Status: Failed (Type: Original)
>>>> Last Update: Jan 13, 2017 02:06:44 EST

Event Name           | Status               | Start                          | End                            | Duration            
-------------------- | -------------------- | ------------------------------ | ------------------------------ | --------------------
ApplicationStop      | Succeeded            | Jan 13, 2017 01:49:42 EST      | Jan 13, 2017 01:46:10 EST      | 3 min, 32 sec       
DownloadBundle       | Succeeded            | Jan 13, 2017 01:53:21 EST      | Jan 13, 2017 01:53:21 EST      | 0 sec               
Install              | Failed               | Jan 13, 2017 01:57:21 EST      | Jan 13, 2017 02:06:44 EST      | 9 min, 23 sec       

Install event failed! Something failed.

Script: scripts/my-install.sh
Error Code: ScriptFailed

[stdout] test

>>>> Instance ID: ijkl
>>>> Status: Skipped (Type: Original)
>>>> Last Update: Jan 13, 2017 02:07:09 EST

Event Name           | Status               | Start                          | End                            | Duration            
-------------------- | -------------------- | ------------------------------ | ------------------------------ | --------------------
ApplicationStop      | Skipped              | N/A                            | N/A                            |                     
DownloadBundle       | Skipped              | N/A                            | N/A                            |                     
BeforeInstall        | Skipped              | N/A                            | N/A                            |                     
Install              | Skipped              | N/A                            | N/A                            |                     

TEXT;
        $checker = new HealthChecker($this->clock, 'America/Detroit');
        $actual = $checker->getDeploymentInstancesHealth($this->cd, '1234');

        // Deployment info
        $this->assertSame('Succeeded', $actual['status']);

        // Instances info
        $this->assertSame(['abcd', 'efgh', 'ijkl'], $actual['instances']);
        $this->assertSame($expectedInstanceSummary, $actual['instancesSummary']);
        $this->assertSame($expectedInstanceDetailed, $actual['instancesDetailed']);
    }
}
