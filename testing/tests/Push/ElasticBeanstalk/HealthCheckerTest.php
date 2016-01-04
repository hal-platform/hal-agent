<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use Aws\Result;
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
        $result = new Result([
            'Environments' => [
                ['Status' => 'Terminated', 'Health' => 'Red']
            ]
        ]);

        $this->eb
            ->shouldReceive('describeEnvironments')
            ->with([
                'ApplicationName' => 'appName',
                'EnvironmentIds' => ['envId']
            ])
            ->andReturn($result);

        $checker = new HealthChecker;
        $actual = $checker($this->eb, 'appName', 'envId');

        $this->assertSame('Terminated', $actual['status']);
        $this->assertSame('Red', $actual['health']);
    }

    public function testWeirdResponse()
    {
        $result = new Result([]);

        $this->eb
            ->shouldReceive('describeEnvironments')
            ->with([
                'ApplicationName' => 'appName',
                'EnvironmentIds' => ['envId']
            ])
            ->andReturn($result);

        $checker = new HealthChecker;
        $actual = $checker($this->eb, 'appName', 'envId');

        $this->assertSame(HealthChecker::NON_STANDARD_MISSING, $actual['status']);
        $this->assertSame('Grey', $actual['health']);
    }

    public function testNoEnvFound()
    {
        $result = new Result([
            'Environments' => []
        ]);

        $this->eb
            ->shouldReceive('describeEnvironments')
            ->with([
                'ApplicationName' => 'appName',
                'EnvironmentIds' => ['envId']
            ])
            ->andReturn($result);

        $checker = new HealthChecker;
        $actual = $checker($this->eb, 'appName', 'envId');

        $this->assertSame(HealthChecker::NON_STANDARD_MISSING, $actual['status']);
        $this->assertSame('Grey', $actual['health']);
    }

    public function testMultipleEnvsFoundJustPopsFirstResult()
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
                'EnvironmentIds' => ['envId']
            ])
            ->andReturn($result);

        $checker = new HealthChecker;
        $actual = $checker($this->eb, 'appName', 'envId');

        $this->assertSame('DerpHerp', $actual['status']);
        $this->assertSame('Grey', $actual['health']);
    }
}
