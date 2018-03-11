<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Credential\AWSRoleCredential;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use QL\MCP\Common\Time\Clock;

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

            'bucket' => 'bucket',
            'method' => 'artifact',

            'local_path' => '.',
            'remote_path' => 'file.zip'
        ];

        $this->authenticator
            ->shouldReceive('getS3')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(true);

        $release = $this->createMockRelease();
        $configurator = new Configurator($this->authenticator, $this->clock);

        $actual = $configurator($release);

        $this->assertTrue(isset($actual['sdk']['s3']));
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

        $configurator = new Configurator($this->authenticator, $this->clock);

        $actual = $configurator($release);

        $this->assertSame($expectedPath, $actual['remote_path']);
    }

    public function testAuthenticatorFails()
    {
        $release = $this->createMockRelease();
        $release->target()->withCredential(null);

        $configurator = new Configurator($this->authenticator, $this->clock);

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
                    ->withParameter('s3_method', 'artifact')
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
