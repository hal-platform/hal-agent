<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS\Steps;

use Aws\Ec2\Ec2Client;
use Aws\S3\S3Client;
use Aws\Ssm\SsmClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Hal\Agent\Build\WindowsAWS\AWS\BuilderFinder;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Mockery;

class ConfiguratorTest extends MockeryTestCase
{
    public $em;
    public $authenticator;
    public $finder;

    public $credentialRepo;
    public $ec2;
    public $s3;
    public $ssm;

    public function setUp()
    {
        $this->credentialRepo = Mockery::mock(EntityRepository::class);
        $this->em = Mockery::mock(EntityManagerInterface::class, [
            'getRepository' => $this->credentialRepo
        ]);
        $this->authenticator = Mockery::mock(AWSAuthenticator::class);
        $this->finder = Mockery::mock(BuilderFinder::class);

        $this->ec2 = Mockery::mock(Ec2Client::class);
        $this->s3 = Mockery::mock(S3Client::class);
        $this->ssm = Mockery::mock(SsmClient::class);
    }

    public function testSuccess()
    {
        $release = $this->createMockRelease();
        $credential = new Credential;

        $expectedSDK = [
            's3' => $this->s3,
            'ssm' => $this->ssm,
        ];

        $expected = [
            'instance_id' => 'i-1234',

            'bucket' => 'hal-bucket',
            's3_input_object' => 'hal-job-1234-input.tgz',
            's3_output_object' => 'hal-job-1234-output.tgz',

            'environment_variables' => [
                'HAL_JOB_ID' => '1234',
                'HAL_JOB_TYPE' => 'release',

                'HAL_VCS_COMMIT' => '7de49f3',
                'HAL_VCS_REF' => 'master',

                'HAL_ENVIRONMENT' => 'staging',
                'HAL_APPLICATION' => 'Demo App',

                'HAL_CONTEXT' => ''
            ]
        ];

        $this->credentialRepo
            ->shouldReceive('findOneBy')
            ->with(['isInternal' => true, 'name' => 'Hal System AWS Credential'])
            ->once()
            ->andReturn($credential);

        $this->authenticator
            ->shouldReceive('getS3')
            ->with('us-east-1', $credential->details())
            ->andReturn($this->s3);
        $this->authenticator
            ->shouldReceive('getSSM')
            ->with('us-east-1', $credential->details())
            ->andReturn($this->ssm);
        $this->authenticator
            ->shouldReceive('getEC2')
            ->with('us-east-1', $credential->details())
            ->andReturn($this->ec2);

        $this->finder
            ->shouldReceive('__invoke')
            ->with($this->ec2, 'Name=hal_builder')
            ->andReturn('i-1234');

        $configurator = new Configurator(
            $this->em,
            $this->authenticator,
            $this->finder,
            'us-east-1',
            'Hal System AWS Credential',
            'hal-bucket',
            'Name=hal_builder'
        );

        $actual = $configurator($release);

        $actualSDK = $actual['sdk'];
        unset($actual['sdk']);

        $this->assertSame($expected, $actual);
        $this->assertSame($expectedSDK, $actualSDK);
    }

    private function createMockRelease()
    {
        return (new Release('1234'))
            ->withApplication(
                (new Application)
                    ->withName('Demo App')
            )
            ->withEnvironment(
                (new Environment)
                    ->withName('staging')
            )
            ->withBuild(
                (new Build('1234'))
                    ->withStatus('pending')
                    ->withReference('master')
                    ->withCommit('7de49f3')
            );
    }
}
