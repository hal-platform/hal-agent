<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\Script;

use Hal\Agent\Deploy\Script\Steps\Configurator;
use Hal\Agent\JobExecution;
use Hal\Agent\JobRunner;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\IOTestCase;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Environment;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class ScriptDeployPlatformTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $configurator;
    public $jobRunner;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->configurator = Mockery::mock(Configurator::class);
        $this->jobRunner = Mockery::mock(JobRunner::class);
    }

    public function testMissingConfigFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [];
        $io = $this->io();

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($execution)
            ->andReturn(null);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Script deploy platform is not configured correctly')
            ->once();

        $platform = new ScriptDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->jobRunner
        );
        $platform->setIO($io);

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] Script deploy platform is not configured correctly'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testScriptFailureFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [];
        $io = $this->io();

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($execution)
            ->andReturn([
                'platform' => 'test-platform',
                'scriptExecution' => $execution
            ]);

        $this->jobRunner
            ->shouldReceive('__invoke')
            ->with(
                $job,
                $io,
                $execution,
                $properties
            )
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'There was an error executing the script')
            ->once();

        $platform = new ScriptDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->jobRunner
        );
        $platform->setIO($io);

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] There was an error executing the script'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testSuccess()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [];
        $io = $this->io();

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($execution)
            ->andReturn([
                'platform' => 'test-platform',
                'scriptExecution' => $execution
            ]);

        $this->jobRunner
            ->shouldReceive('__invoke')
            ->with(
                $job,
                $io,
                $execution,
                $properties
            )
            ->andReturn(true);

        $platform = new ScriptDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->jobRunner
        );
        $platform->setIO($io);

        $actual = $platform($job, $execution, $properties);
        $this->assertSame(true, $actual);
    }

    public function generateMockExecution()
    {
        $properties = [
            'platform' => 'linux',
            'deploy' => [
            ]
        ];

        return new JobExecution('script', 'deploy', $properties);
    }

    public function generateMockRelease()
    {
        return (new Release('1234'))
            ->withEnvironment(
                (new Environment('1234'))
                    ->withName('UnitTestEnv')
            );
    }
}
