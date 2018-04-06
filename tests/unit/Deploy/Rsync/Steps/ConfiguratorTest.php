<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\Rsync\Steps;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Hal\Core\Parameters;
use Mockery;

class ConfiguratorTest extends MockeryTestCase
{
    public $logger;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
    }

    public function testSuccess()
    {
        $expected = [
            'remoteUser' => 'root',
            'remoteServer' => 'localhost',
            'remotePath' => '/',
            'syncPath' => 'root@localhost:/',
            'environmentVariables' => [
                'HAL_JOB_ID' => '1234',
                'HAL_JOB_TYPE' => 'release',
                'HAL_VCS_COMMIT' => 'hash',
                'HAL_VCS_REF' => 'master',
                'HAL_ENVIRONMENT' => 'test',
                'HAL_APPLICATION' => 'TestApp',
                'HAL_CONTEXT' => 'context',
                'HAL_HOSTNAME' => 'localhost',
                'HAL_PATH' => '/'
            ]
        ];

        $release = $this->createMockRelease();

        $configurator = new Configurator($this->logger, 'root');

        $actual = $configurator($release);
        $this->assertSame($expected, $actual);
    }

    public function testNoServersFails()
    {
        $release = $this->createMockRelease();
        $release->target()->withParameter(Parameters::TARGET_RSYNC_SERVERS, null);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::type('string'));

        $configurator = new Configurator($this->logger, 'root');

        $actual = $configurator($release);
        $this->assertSame(null, $actual);
    }

    public function testMoreThanOneServerFails()
    {
        $release = $this->createMockRelease();
        $release->target()->withParameter(Parameters::TARGET_RSYNC_SERVERS, 'localhost,127.0.0.1,::1');

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::type('string'), Mockery::type('array'));

        $configurator = new Configurator($this->logger, 'root');

        $actual = $configurator($release);
        $this->assertSame(null, $actual);
    }

    public function testMoreThanOneServerSucceeds()
    {
        // When completing this test, remove the above test
        $this->markTestIncomplete('Multiple Rsync Servers not allowed yet. Related task: issue #265');
    }

    private function createMockRelease()
    {
        return (new Release('1234'))
            ->withApplication(
                (new Application('5678'))
                    ->withName('TestApp')
            )
            ->withBuild(
                (new Build)
                    ->withCommit('hash')
                    ->withReference('master')
            )
            ->withEnvironment(
                (new Environment)
                    ->withName('test')
            )
            ->withTarget(
                (new Target)
                    ->withParameter(Parameters::TARGET_RSYNC_SERVERS, 'localhost')
                    ->withParameter(Parameters::TARGET_RSYNC_REMOTE_PATH, '/')
                    ->withParameter(Parameters::TARGET_CONTEXT, 'context')
            );
    }
}
