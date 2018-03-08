<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Hal\Core\Entity\Application;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Credential\AWSRoleCredential;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use PHPUnit\Framework\TestCase;
use QL\MCP\Common\Time\Clock;

class ConfiguratorTest extends TestCase
{
    public $clock;
    public $credential;

    public function setUp()
    {
        $this->clock = new Clock('2018-03-07T15:29:55.011111Z', 'UTC');
        $this->credential = (new Credential)
            ->withDetails(
                new AWSRoleCredential
            );
    }

    public function testSuccess()
    {
        $expected = [
            'aws' => [
                'region' => 'us-test-1',
                'credential' => $this->credential->details()
            ],
            's3' => [
                'bucket' => 'bucket',
                'method' => 'artifact',
                'file' => 'file.zip',
                'src' => '.'
            ]
        ];

        $release = $this->createMockRelease();
        $configurator = new Configurator($this->clock);

        $actual = $configurator($release);

        $this->assertSame($expected, $actual);
    }

    public function testAppIDToken()
    {
        $expectedFile = '1234.zip';

        $release = $this->createMockRelease();
        $release->target()->withParameter(Target::PARAM_REMOTE_PATH, '$APPID.zip');

        $configurator = new Configurator($this->clock);

        $actual = $configurator($release);

        $this->assertSame($expectedFile, $actual['s3']['file']);
    }

    public function testAppNameToken()
    {
        $expectedFile = 'TestApp.zip';

        $release = $this->createMockRelease();
        $release->target()->withParameter(Target::PARAM_REMOTE_PATH, '$APP.zip');

        $configurator = new Configurator($this->clock);

        $actual = $configurator($release);

        $this->assertSame($expectedFile, $actual['s3']['file']);
    }

    public function testBuildIDToken()
    {
        $expectedFile = '5678.zip';

        $release = $this->createMockRelease();
        $release->target()->withParameter(Target::PARAM_REMOTE_PATH, '$BUILDID.zip');

        $configurator = new Configurator($this->clock);

        $actual = $configurator($release);

        $this->assertSame($expectedFile, $actual['s3']['file']);
    }

    public function testPushIDToken()
    {
        $expectedFile = '9000.zip';

        $release = $this->createMockRelease();
        $release->target()->withParameter(Target::PARAM_REMOTE_PATH, '$PUSHID.zip');

        $configurator = new Configurator($this->clock);

        $actual = $configurator($release);

        $this->assertSame($expectedFile, $actual['s3']['file']);
    }

    public function testDateToken()
    {
        $expectedFile = '20180307.zip';

        $release = $this->createMockRelease();
        $release->target()->withParameter(Target::PARAM_REMOTE_PATH, '$DATE.zip');

        $configurator = new Configurator($this->clock);

        $actual = $configurator($release);

        $this->assertSame($expectedFile, $actual['s3']['file']);
    }

    public function testTimeToken()
    {
        $expectedFile = '152955.zip';

        $release = $this->createMockRelease();
        $release->target()->withParameter(Target::PARAM_REMOTE_PATH, '$TIME.zip');

        $configurator = new Configurator($this->clock);

        $actual = $configurator($release);

        $this->assertSame($expectedFile, $actual['s3']['file']);
    }

    private function createMockRelease()
    {
        return (new Release('9000'))
            ->withApplication(
                (new Application('1234'))
                    ->withName('TestApp')
            )
            ->withBuild(
                (new Build('5678'))
            )
            ->withEnvironment(
                (new Environment)
                    ->withName('test')
            )
            ->withTarget(
                (new Target)
                    ->withParameter(Target::PARAM_REGION, 'us-test-1')
                    ->withParameter(Target::PARAM_S3_METHOD, 'artifact')
                    ->withParameter(Target::PARAM_BUCKET, 'bucket')
                    ->withParameter(Target::PARAM_LOCAL_PATH, '.')
                    ->withParameter(Target::PARAM_REMOTE_PATH, 'file.zip')
                    ->withCredential($this->credential)
            );
    }
}
