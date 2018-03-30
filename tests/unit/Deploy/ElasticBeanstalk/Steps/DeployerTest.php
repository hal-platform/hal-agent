<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticBeanstalk\Steps;

use Aws\CommandInterface;
use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Aws\ElasticBeanstalk\Exception\ElasticBeanstalkException;
use Aws\Result;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;

class DeployerTest extends MockeryTestCase
{
    public $logger;
    public $eb;
    public $streamer;


    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->eb = Mockery::mock(ElasticBeanstalkClient::class);
        $this->streamer = function() {return 'file';};
    }

    public function testSuccess()
    {
        $this->eb
            ->shouldReceive('describeApplicationVersions')
            ->andReturn(new Result((['ApplicationVersions' => []])));
        $this->eb
            ->shouldReceive('createApplicationVersion')
            ->once();
        $this->eb
            ->shouldReceive('updateEnvironment')
            ->once();

        $deployer = new Deployer($this->logger);
        $actual = $deployer(
            $this->eb,
            'appName',
            'envId',
            'bucket-name',
            's3_object.zip',
            'b.1234',
            'p.abcd',
            'test',
            'testDescription'
        );

        $this->assertSame(true, $actual);
    }

    public function testVersionAlreadyExistsFails()
    {
        $this->eb
            ->shouldReceive('describeApplicationVersions')
            ->andReturn(new Result(['ApplicationVersions' => ['version1', 'version2']]));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $deployer = new Deployer($this->logger);
        $actual = $deployer(
            $this->eb,
            'appName',
            'envId',
            'bucket-name',
            's3_object.zip',
            'b.1234',
            'p.abcd',
            'test',
            'testDescription'
        );

        $this->assertSame(false, $actual);
    }

    public function testebBlowsUpDuringUpdate()
    {
        $this->eb
            ->shouldReceive('describeApplicationVersions')
            ->andReturn(new Result(['ApplicationVersions' => []]));

        $this->eb
            ->shouldReceive('createApplicationVersion')
            ->once();
        $this->eb
            ->shouldReceive('updateEnvironment')
            ->andThrow(new ElasticBeanstalkException('', Mockery::mock(CommandInterface::class)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $deployer = new Deployer($this->logger);
        $actual = $deployer(
            $this->eb,
            'appName',
            'envId',
            'bucket-name',
            's3_object.zip',
            'b.1234',
            'p.abcd',
            'test',
            'testDescription'
        );

        $this->assertSame(false, $actual);
    }

}
