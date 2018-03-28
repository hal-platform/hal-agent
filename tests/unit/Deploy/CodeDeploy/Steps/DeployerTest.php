<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\CodeDeploy\Steps;

use Aws\CodeDeploy\CodeDeployClient;
use Aws\CommandInterface;
use Aws\Result;
use Hal\Agent\Logger\EventLogger;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Release;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;

use Aws\Exception\AwsException;

class DeployerTest extends MockeryTestCase
{
    public $cd;
    public $logger;

    public function setUp()
    {
        $this->cd = Mockery::mock(CodeDeployClient::class);
        $this->logger = Mockery::Mock(EventLogger::class);
    }

    public function testSuccess()
    {
        $job = $this->createMockRelease();

        $this->cd
            ->shouldReceive('createDeployment')
            ->with([
                'applicationName' => 'app',
                'deploymentGroupName' => 'grp',
                'deploymentConfigName' => 'cfg',

                'description' => 'uri',
                'ignoreApplicationStopFailures' => false,
                'fileExistsBehavior' => 'OVERWRITE',

                'revision' => [
                    'revisionType' => 'S3',
                    's3Location' => [
                        'bucket' => 'bucket',
                        'bundleType' => 'tgz',
                        'key' => 'remote_file.tar.gz'
                    ]
                ]
            ])
            ->andReturn(new Result([
                'deploymentId' => '1234'
            ]));

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), Mockery::any());

        $deployer = new Deployer($this->logger);

        $actual = ($deployer)(
            $job,
            $this->cd,
            'bucket',
            'remote_file.tar.gz',
            'app',
            'grp',
            'cfg',
            'uri'
        );

        $this->assertInternalType('array', $actual);
        $this->assertArrayHasKey('codeDeployID', $actual);
    }

    public function testCreateDeploymentFails()
    {
        $job = $this->createMockRelease();

        $this->cd
            ->shouldReceive('createDeployment')
            ->with([
                'applicationName' => 'app',
                'deploymentGroupName' => 'grp',
                'deploymentConfigName' => 'cfg',

                'description' => 'uri',
                'ignoreApplicationStopFailures' => false,
                'fileExistsBehavior' => 'OVERWRITE',

                'revision' => [
                    'revisionType' => 'S3',
                    's3Location' => [
                        'bucket' => 'bucket',
                        'bundleType' => 'tgz',
                        'key' => 'remote_file.tar.gz'
                    ]
                ]
            ])
            ->andThrow(new AwsException('message', Mockery::mock(CommandInterface::class)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any());

        $deployer = new Deployer($this->logger);

        $actual = ($deployer)(
            $job,
            $this->cd,
            'bucket',
            'remote_file.tar.gz',
            'app',
            'grp',
            'cfg',
            'uri'
        );

        $this->assertSame(null, $actual);
    }

    private function createMockRelease()
    {
        return (new Release('1234'))
            ->withEnvironment(
                (new Environment)
                    ->withName('test')
            );
    }
}
