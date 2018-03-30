<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticBeanstalk\Steps;

use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Aws\Result;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;

use DateTime;
use QL\MCP\Common\Time\Clock;
use QL\MCP\Common\Time\TimePoint;

class HealthCheckerTest extends MockeryTestCase
{
    public $eb;
    public $clock;

    public function setUp()
    {
        $this->eb = Mockery::mock(ElasticBeanstalkClient::class);
        $this->clock = new Clock;
    }

    public function testGetHealthIsSuccessful()
    {
        $result = new Result([
            'Environments' => [
                ['Status' => 'Terminated', 'Health' => 'Red']
            ]
        ]);

        $this->eb
            ->shouldReceive('describeEnvironments')
            ->with([
                'ApplicationName' => 'appName',
                'EnvironmentIds' => ['e-envId']
            ])
            ->andReturn($result);

        $checker = new HealthChecker($this->clock, 'America/Detroit');
        $actual = $checker->getEnvironmentHealth($this->eb, 'appName', 'e-envId');

        $this->assertSame('Terminated', $actual['status']);
        $this->assertSame('Red', $actual['health']);
    }

    public function testGetHealthWeirdResponse()
    {
        $result = new Result([]);

        $this->eb
            ->shouldReceive('describeEnvironments')
            ->with([
                'ApplicationName' => 'appName',
                'EnvironmentNames' => ['envId']
            ])
            ->andReturn($result);

        $checker = new HealthChecker($this->clock, 'America/Detroit');
        $actual = $checker->getEnvironmentHealth($this->eb, 'appName', 'envId');

        $this->assertSame(HealthChecker::NON_STANDARD_MISSING, $actual['status']);
        $this->assertSame('Grey', $actual['health']);
    }

    public function testGetHealthNoEnvFound()
    {
        $result = new Result([
            'Environments' => []
        ]);

        $this->eb
            ->shouldReceive('describeEnvironments')
            ->with([
                'ApplicationName' => 'appName',
                'EnvironmentIds' => ['e-1234']
            ])
            ->andReturn($result);

        $checker = new HealthChecker($this->clock, 'America/Detroit');
        $actual = $checker->getEnvironmentHealth($this->eb, 'appName', 'e-1234');

        $this->assertSame(HealthChecker::NON_STANDARD_MISSING, $actual['status']);
        $this->assertSame('Grey', $actual['health']);
    }

    public function testGetHealthMultipleEnvsFoundJustPopsFirstResult()
    {
        $result = new Result([
            'Environments' => [
                ['Status' => 'DerpHerp', 'Health' => 'Grey'],
                ['Status' => 'Terminated', 'Health' => 'Red']
            ]
        ]);

        $this->eb
            ->shouldReceive('describeEnvironments')
            ->with([
                'ApplicationName' => 'appName',
                'EnvironmentIds' => ['e-1234']
            ])
            ->andReturn($result);

        $checker = new HealthChecker($this->clock, 'America/Detroit');
        $actual = $checker->getEnvironmentHealth($this->eb, 'appName', 'e-1234');

        $this->assertSame('DerpHerp', $actual['status']);
        $this->assertSame('Grey', $actual['health']);
    }

    public function testGetRecentEvents()
    {
        $result = new Result([
            "Events" => [
                [
                    "EventDate" => "2017-04-24T19:34:42+00:00",
                    "Message" => "Environment health has transitioned from RED to GREEN",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T19:32:34+00:00",
                    "Message" => "Environment update completed successfully.",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "RequestId" => "0e95c9da-291c-11e7-bef8-875246bb1f49",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T19:32:34+00:00",
                    "Message" => "New application version was deployed to running EC2 instances.",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "RequestId" => "0e95c9da-291c-11e7-bef8-875246bb1f49",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T19:31:46+00:00",
                    "Message" => "UpdateAppVersion Completed",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "RequestId" => "0e95c9da-291c-11e7-bef8-875246bb1f49",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T19:31:36+00:00",
                    "Message" => "Started Application Update",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "RequestId" => "0e95c9da-291c-11e7-bef8-875246bb1f49",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T19:31:17+00:00",
                    "Message" => "Deploying new version to instance(s).",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "RequestId" => "0e95c9da-291c-11e7-bef8-875246bb1f49",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T19:30:14+00:00",
                    "Message" => "Environment update is starting.",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "RequestId" => "0e95c9da-291c-11e7-bef8-875246bb1f49",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T19:19:45+00:00",
                    "Message" => "createApplicationVersion completed successfully.",
                    "ApplicationName" => "my-test-application",
                    "VersionLabel" => "v1.0-alpha3",
                    "RequestId" => "f9961950-2922-11e7-91df-cbaf68bbc1eb",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T19:19:45+00:00",
                    "Message" => "Created new Application Version: v1.0-alpha3",
                    "ApplicationName" => "my-test-application",
                    "VersionLabel" => "v1.0-alpha3",
                    "RequestId" => "f9961950-2922-11e7-91df-cbaf68bbc1eb",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T19:19:45+00:00",
                    "Message" => "createApplicationVersion is starting.",
                    "ApplicationName" => "my-test-application",
                    "VersionLabel" => "v1.0-alpha3",
                    "RequestId" => "f9961950-2922-11e7-91df-cbaf68bbc1eb",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T18:29:42+00:00",
                    "Message" => "Environment health has transitioned from YELLOW to RED",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "Severity" => "WARN"
                ],
                [
                    "EventDate" => "2017-04-24T18:27:42+00:00",
                    "Message" => "Environment health has transitioned from GREEN to YELLOW",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "Severity" => "WARN"
                ],
                [
                    "EventDate" => "2017-04-24T18:27:42+00:00",
                    "Message" => "Elastic Load Balancer myelb-testing-1 has zero healthy instances.",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "Severity" => "WARN"
                ],
                [
                    "EventDate" => "2017-04-24T18:23:57+00:00",
                    "Message" => "Environment update completed successfully.",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "RequestId" => "14b23b3d-291b-11e7-99f2-0f26137c658d",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T18:23:57+00:00",
                    "Message" => "New application version was deployed to running EC2 instances.",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "RequestId" => "14b23b3d-291b-11e7-99f2-0f26137c658d",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T18:23:42+00:00",
                    "Message" => "UpdateAppVersion Completed",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "RequestId" => "14b23b3d-291b-11e7-99f2-0f26137c658d",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T18:23:41+00:00",
                    "Message" => "Started Application Update",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "RequestId" => "14b23b3d-291b-11e7-99f2-0f26137c658d",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T18:23:21+00:00",
                    "Message" => "Deploying new version to instance(s).",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "RequestId" => "14b23b3d-291b-11e7-99f2-0f26137c658d",
                    "Severity" => "INFO"
                ],
                [
                    "EventDate" => "2017-04-24T18:23:15+00:00",
                    "Message" => "Environment update is starting.",
                    "ApplicationName" => "my-test-application",
                    "EnvironmentName" => "myapp-staging-env",
                    "RequestId" => "14b23b3d-291b-11e7-99f2-0f26137c658d",
                    "Severity" => "INFO"
                ]
            ]
        ]);

        $history = <<<LOG
Time                           | Severity   | Application                    | Environment                    | Message             
------------------------------ | ---------- | ------------------------------ | ------------------------------ | --------------------
Apr 24, 2017 03:34:42 EDT      | INFO       | my-test-application            | myapp-staging-env              | Environment health has transitioned from RED to GREEN
Apr 24, 2017 03:32:34 EDT      | INFO       | my-test-application            | myapp-staging-env              | Environment update completed successfully.
Apr 24, 2017 03:32:34 EDT      | INFO       | my-test-application            | myapp-staging-env              | New application version was deployed to running EC2 instances.
Apr 24, 2017 03:31:46 EDT      | INFO       | my-test-application            | myapp-staging-env              | UpdateAppVersion Completed
Apr 24, 2017 03:31:36 EDT      | INFO       | my-test-application            | myapp-staging-env              | Started Application Update
Apr 24, 2017 03:31:17 EDT      | INFO       | my-test-application            | myapp-staging-env              | Deploying new version to instance(s).
Apr 24, 2017 03:30:14 EDT      | INFO       | my-test-application            | myapp-staging-env              | Environment update is starting.
Apr 24, 2017 03:19:45 EDT      | INFO       | my-test-application            | N/A                            | createApplicationVersion completed successfully.
Apr 24, 2017 03:19:45 EDT      | INFO       | my-test-application            | N/A                            | Created new Application Version: v1.0-alpha3
Apr 24, 2017 03:19:45 EDT      | INFO       | my-test-application            | N/A                            | createApplicationVersion is starting.
Apr 24, 2017 02:29:42 EDT      | WARN       | my-test-application            | myapp-staging-env              | Environment health has transitioned from YELLOW to RED
Apr 24, 2017 02:27:42 EDT      | WARN       | my-test-application            | myapp-staging-env              | Environment health has transitioned from GREEN to YELLOW
Apr 24, 2017 02:27:42 EDT      | WARN       | my-test-application            | myapp-staging-env              | Elastic Load Balancer myelb-testing-1 has zero healthy instances.
Apr 24, 2017 02:23:57 EDT      | INFO       | my-test-application            | myapp-staging-env              | Environment update completed successfully.
Apr 24, 2017 02:23:57 EDT      | INFO       | my-test-application            | myapp-staging-env              | New application version was deployed to running EC2 instances.
Apr 24, 2017 02:23:42 EDT      | INFO       | my-test-application            | myapp-staging-env              | UpdateAppVersion Completed
Apr 24, 2017 02:23:41 EDT      | INFO       | my-test-application            | myapp-staging-env              | Started Application Update
Apr 24, 2017 02:23:21 EDT      | INFO       | my-test-application            | myapp-staging-env              | Deploying new version to instance(s).
Apr 24, 2017 02:23:15 EDT      | INFO       | my-test-application            | myapp-staging-env              | Environment update is starting.
LOG;

        $this->eb
            ->shouldReceive('describeEvents')
            ->with([
                'ApplicationName' => 'app-name',
                'MaxRecords' => 25
            ])
            ->andReturn($result);

        $checker = new HealthChecker($this->clock, 'America/Detroit');
        $actual = $checker->getEventHistory($this->eb, 'app-name');

        $this->assertSame($history, $actual['eventHistory']);

    }
}
