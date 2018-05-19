<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\CodeDeploy\Steps;

use Aws\CodeDeploy\CodeDeployClient;
use Hal\Agent\Logger\EventLogger;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Credential\AWSRoleCredential;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Hal\Core\Parameters;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use QL\MCP\Common\Clock;

class ConfiguratorTest extends MockeryTestCase
{
    public $authenticator;
    public $cd;
    public $clock;
    public $logger;

    public function setUp()
    {
        $this->authenticator = Mockery::mock(AWSAuthenticator::class);
        $this->cd = Mockery::mock(CodeDeployClient::class);
        $this->clock = new Clock('2018-03-07T12:15:30Z', 'UTC');
        $this->logger = Mockery::Mock(EventLogger::class);
    }

    public function testSuccess()
    {
        $expected = [
            'region' => 'us-test-1',

            'bucket' => 'bucket',

            'application' => 'app',
            'group' => 'grp',
            'configuration' => 'cfg',

            'local_path' => '.',
            'remote_path' => 'file.zip',
            'deployment_description' => '[test]haltest/release/1234'
        ];

        $this->authenticator
            ->shouldReceive('getS3')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(true);

        $this->authenticator
            ->shouldReceive('getCD')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn($this->cd);

        $release = $this->createMockRelease();
        $configurator = new Configurator(
            $this->logger,
            $this->clock,
            $this->authenticator,
            'haltest'
        );

        $actual = $configurator($release);

        $this->assertTrue(isset($actual['sdk']['s3']));
        $this->assertTrue(isset($actual['sdk']['cd']));
        unset($actual['sdk']);

        $this->assertSame($expected, $actual);
    }

    public function testRemotePathTokenReplacements()
    {
        $inputPath = '$JOBID-$APPID-$APP/$ENV/$DATE_$TIME.zip';
        $expectedPath = '1234-5678-TestApp/test/20180307_121530.zip';

        $release = $this->createMockRelease();
        $release->target()->withParameter('path', $inputPath);

        $this->authenticator
            ->shouldReceive('getS3')
            ->andReturn(true);

        $this->authenticator
            ->shouldReceive('getCD')
            ->andReturn($this->cd);

        $configurator = new Configurator(
            $this->logger,
            $this->clock,
            $this->authenticator,
            'haltest'
        );

        $actual = $configurator($release);

        $this->assertSame($expectedPath, $actual['remote_path']);
    }

    public function testAuthenticatorFails()
    {
        $release = $this->createMockRelease();
        $release->target()->withCredential(null);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any());

        $configurator = new Configurator(
            $this->logger,
            $this->clock,
            $this->authenticator,
            'haltest'
        );

        $actual = $configurator($release);

        $this->assertSame(null, $actual);
    }

    private function createMockRelease()
    {
        return (new Release('1234'))
            ->withApplication(
                (new Application('5678'))
                    ->withName('TestApp')
            )
            ->withEnvironment(
                (new Environment)
                    ->withName('test')
            )
            ->withTarget(
                (new Target)
                    ->withParameter(Parameters::TARGET_REGION, 'us-test-1')
                    ->withParameter(Parameters::TARGET_S3_BUCKET, 'bucket')
                    ->withParameter(Parameters::TARGET_S3_METHOD, 'artifact')
                    ->withParameter(Parameters::TARGET_CD_APP, 'app')
                    ->withParameter(Parameters::TARGET_CD_GROUP, 'grp')
                    ->withParameter(Parameters::TARGET_CD_CONFIG, 'cfg')
                    ->withParameter(Parameters::TARGET_S3_LOCAL_PATH, '.')
                    ->withParameter(Parameters::TARGET_S3_REMOTE_PATH, 'file.zip')
                    ->withCredential(
                        (new Credential)
                            ->withDetails(
                                new AWSRoleCredential
                            )
                    )
            );
    }
}
