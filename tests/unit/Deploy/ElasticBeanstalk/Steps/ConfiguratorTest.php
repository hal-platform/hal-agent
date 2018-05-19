<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticBeanstalk\Steps;

use Aws\S3\S3Client;
use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Credential\AWSRoleCredential;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;
use QL\MCP\Common\Clock;

class ConfiguratorTest extends MockeryTestCase
{

    public $authenticator;
    public $clock;
    public $credential;

    public function setUp()
    {
        $this->authenticator = Mockery::mock(AWSAuthenticator::class);
        $this->clock = new Clock('2018-03-07T12:15:30Z', 'UTC');
    }

    public function testSuccess()
    {
        $expected = [
            'region' => 'us-test-1',

            'application' => 'application',
            'environment' => 'environment',

            'bucket' => 'bucket',

            'local_path' => '.',
            'remote_path' => 'file.zip',
            'deployment_description' => '[test]haltest/release/1234'
        ];

        $this->authenticator->shouldReceive('getEB')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(Mockery::Mock(ElasticBeanstalkClient::class));

        $this->authenticator->shouldReceive('getS3')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(Mockery::mock(S3Client::class));

        $release = $this->createMockRelease();
        $configurator = new Configurator($this->authenticator,
                                         $this->clock,
                                         'haltest');

        $actual = $configurator($release);

        $this->assertTrue(isset($actual['sdk']['s3']));
        $this->assertTrue(isset($actual['sdk']['eb']));
        unset($actual['sdk']);

        $this->assertSame($expected, $actual);
    }

    public function testConfiguratorError()
    {
        $release = $this->createMockRelease();

        $this->authenticator->shouldReceive('getEB')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(null);
        $this->authenticator->shouldReceive('getEB')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(true);
        $this->authenticator->shouldReceive('getEB')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(null);

        $this->authenticator->shouldReceive('getS3')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(true);
        $this->authenticator->shouldReceive('getS3')
            ->with('us-test-1', Mockery::any())
            ->times(2)
            ->andReturn(null);

        $configurator = new Configurator($this->authenticator,
                                         $this->clock,
                                         'haltest');

        for ($i = 1; $i <= 3; $i++) {
            $actual = $configurator($release);

            $this->assertSame(null, $actual);
        }
    }

    public function testRemotePathTokenReplacements()
    {
        $inputPath = '$JOBID-$APPID-$APP/$ENV/$DATE_$TIME.zip';
        $expectedPath = '1234-5678-TestApp/test/20180307_121530.zip';

        $release = $this->createMockRelease();
        $release->target()->withParameter('path', $inputPath);

        $this->authenticator->shouldReceive('getEB')
            ->with('us-test-1', Mockery::any())
            ->andReturn(true);

        $this->authenticator->shouldReceive('getS3')
            ->with('us-test-1', Mockery::any())
            ->andReturn(true);

        $configurator = new Configurator($this->authenticator,
                                         $this->clock,
                                         'haltest');

        $actual = $configurator($release);

        $this->assertSame($expectedPath, $actual['remote_path']);
    }

    public function testAuthenticatorFails()
    {
        $release = $this->createMockRelease();
        $release->target()->withCredential(null);

        $configurator = new Configurator($this->authenticator,
                                         $this->clock,
                                         'haltest');

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
                    ->withParameter('region', 'us-test-1')
                    ->withParameter('application', 'application')
                    ->withParameter('environment', 'environment')
                    ->withParameter('bucket', 'bucket')
                    ->withParameter('source', '.')
                    ->withParameter('path', 'file.zip')
                    ->withCredential(
                        (new Credential)
                            ->withDetails(
                                new AWSRoleCredential
                            )
                    )
            );
    }
}
