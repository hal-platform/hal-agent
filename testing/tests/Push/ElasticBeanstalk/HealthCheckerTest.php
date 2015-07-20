<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use Mockery;
use PHPUnit_Framework_TestCase;

class HealthCheckerTest extends PHPUnit_Framework_TestCase
{
    public $eb;

    public function setUp()
    {
        $this->eb = Mockery::mock('Aws\ElasticBeanstalk\ElasticBeanstalkClient');
    }

    public function testSuccess()
    {
        $this->eb
            ->shouldReceive('describeEnvironments')
            ->with([
                'ApplicationName' => 'appName',
                'EnvironmentIds' => ['envId']
            ])
            ->andReturn(['Environments' => [
                ['Status' => 'Terminated', 'Health' => 'Red']
            ]]);

        $checker = new HealthChecker;
        $actual = $checker($this->eb, 'appName', 'envId');

        $this->assertSame('Terminated', $actual['status']);
        $this->assertSame('Red', $actual['health']);
    }

    public function testWeirdResponse()
    {
        $this->eb
            ->shouldReceive('describeEnvironments')
            ->with([
                'ApplicationName' => 'appName',
                'EnvironmentIds' => ['envId']
            ])
            ->andReturn([]);

        $checker = new HealthChecker;
        $actual = $checker($this->eb, 'appName', 'envId');

        $this->assertSame(HealthChecker::NON_STANDARD_MISSING, $actual['status']);
        $this->assertSame('Grey', $actual['health']);
    }

    public function testNoEnvFound()
    {
        $this->eb
            ->shouldReceive('describeEnvironments')
            ->with([
                'ApplicationName' => 'appName',
                'EnvironmentIds' => ['envId']
            ])
            ->andReturn(['Environments' => [
            ]]);

        $checker = new HealthChecker;
        $actual = $checker($this->eb, 'appName', 'envId');

        $this->assertSame(HealthChecker::NON_STANDARD_MISSING, $actual['status']);
        $this->assertSame('Grey', $actual['health']);
    }

    public function testTooManyEnvsFound()
    {
        $this->eb
            ->shouldReceive('describeEnvironments')
            ->with([
                'ApplicationName' => 'appName',
                'EnvironmentIds' => ['envId']
            ])
            ->andReturn(['Environments' => [
                ['derp'],
                ['derp2'],
            ]]);

        $checker = new HealthChecker;
        $actual = $checker($this->eb, 'appName', 'envId');

        $this->assertSame(HealthChecker::NON_STANDARD_MULTIPLE, $actual['status']);
        $this->assertSame('Grey', $actual['health']);
    }
}
