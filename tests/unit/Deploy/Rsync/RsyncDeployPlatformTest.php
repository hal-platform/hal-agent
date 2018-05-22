<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\Rsync;

use Hal\Agent\Deploy\Rsync\Steps\Configurator;
use Hal\Agent\Deploy\Rsync\Steps\Verifier;
use Hal\Agent\Deploy\Rsync\Steps\CommandRunner;
use Hal\Agent\Deploy\Rsync\Steps\Deployer;
use Hal\Agent\JobExecution;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\IOTestCase;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Release;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class RsyncDeployPlatformTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $output;
    public $logger;

    public $configurator;
    public $verifier;
    public $runner;
    public $deployer;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);

        $this->configurator = Mockery::mock(Configurator::class);
        $this->verifier = Mockery::mock(Verifier::class);
        $this->runner = Mockery::mock(CommandRunner::class);
        $this->deployer = Mockery::mock(Deployer::class);
    }

    public function testSuccess()
    {
        $job = new Release();

        $jobExecution = new JobExecution('rsync', 'deploy', [
            'rsync_exclude' => [
                'excludeme.jpg'
            ],
            'rsync_before' => [
                'echo "Code will be deployed"'
            ],
            'rsync_after' => [
                'echo "Code is deployed"'
            ]
        ]);

        $properties = [
            'workspace_path' => '/tmp/1234'
        ];

        $platformConfig = [
            'remoteUser' => 'root',
            'remoteServer' => 'localhost',
            'remotePath' => '/',
            'environmentVariables' => [
                '$HOME' => '/'
            ]
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($job)
            ->andReturn($platformConfig);

        $this->verifier
            ->shouldReceive('__invoke')
            ->with('root', 'localhost', '/')
            ->andReturn(true);

        $this->runner
            ->shouldReceive('__invoke')
            ->with('root', 'localhost', '/', ['echo "Code will be deployed"'], ['$HOME' => '/'])
            ->andReturn(true);

        $this->deployer
            ->shouldReceive('__invoke')
            ->with('/tmp/1234/workspace', 'root', 'localhost', '/', ['excludeme.jpg'])
            ->andReturn(true);

        $this->runner
            ->shouldReceive('__invoke')
            ->with('root', 'localhost', '/', ['echo "Code is deployed"'], ['$HOME' => '/'])
            ->andReturn(true);

        $platform = new RsyncDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->runner,
            $this->deployer
        );

        $actual = $platform($job, $jobExecution, $properties);
        $this->assertSame(true, $actual);
    }

    public function testNullConfigurationFails()
    {
        $job = new Release();
        $jobExecution = new JobExecution('rsync', 'deploy', []);
        $properties = [
            'workspace_path' => '/tmp/1234'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($job)
            ->andReturn(null);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any());

        $platform = new RsyncDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->runner,
            $this->deployer
        );

        $actual = $platform($job, $jobExecution, $properties);
        $this->assertSame(false, $actual);
    }

    public function testInvalidTargetFails()
    {
        $job = new Release();
        $jobExecution = new JobExecution('rsync', 'deploy', []);
        $properties = [
            'workspace_path' => '/tmp/1234'
        ];

        $platformConfig = [
            'remoteUser' => 'root',
            'remoteServer' => 'localhost',
            'remotePath' => '/'
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($job)
            ->andReturn($platformConfig);

        $this->verifier
            ->shouldReceive('__invoke')
            ->with('root', 'localhost', '/')
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any());

        $platform = new RsyncDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->runner,
            $this->deployer
        );

        $actual = $platform($job, $jobExecution, $properties);
        $this->assertSame(false, $actual);
    }

    public function testUnableToRunBeforeCommandsFails()
    {
        $job = new Release();
        $jobExecution = new JobExecution('rsync', 'deploy', [
            'rsync_before' => [
                'echo "Code will be deployed"'
            ]
        ]);

        $properties = [
            'workspace_path' => '/tmp/1234'
        ];

        $platformConfig = [
            'remoteUser' => 'root',
            'remoteServer' => 'localhost',
            'remotePath' => '/',
            'environmentVariables' => [
                '$HOME' => '/'
            ]
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($job)
            ->andReturn($platformConfig);

        $this->verifier
            ->shouldReceive('__invoke')
            ->with('root', 'localhost', '/')
            ->andReturn(true);

        $this->runner
            ->shouldReceive('__invoke')
            ->with('root', 'localhost', '/', ['echo "Code will be deployed"'], ['$HOME' => '/'])
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any());

        $platform = new RsyncDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->runner,
            $this->deployer
        );

        $actual = $platform($job, $jobExecution, $properties);
        $this->assertSame(false, $actual);
    }

    public function testUnableToDeployFails()
    {
        $job = new Release();
        $jobExecution = new JobExecution('rsync', 'deploy', [
            'rsync_exclude' => [
            ],
            'rsync_before' => [
                'echo "Code will be deployed"'
            ]
        ]);

        $properties = [
            'workspace_path' => '/tmp/1234'
        ];

        $platformConfig = [
            'remoteUser' => 'root',
            'remoteServer' => 'localhost',
            'remotePath' => '/',
            'environmentVariables' => [
                '$HOME' => '/'
            ]
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($job)
            ->andReturn($platformConfig);

        $this->verifier
            ->shouldReceive('__invoke')
            ->with('root', 'localhost', '/')
            ->andReturn(true);

        $this->runner
            ->shouldReceive('__invoke')
            ->with('root', 'localhost', '/', ['echo "Code will be deployed"'], ['$HOME' => '/'])
            ->andReturn(true);

        $this->deployer
            ->shouldReceive('__invoke')
            ->with('/tmp/1234/workspace', 'root', 'localhost', '/', [])
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any());

        $platform = new RsyncDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->runner,
            $this->deployer
        );

        $actual = $platform($job, $jobExecution, $properties);
        $this->assertSame(false, $actual);
    }

    public function testUnableToRunAfterCommandsFails()
    {
        $job = new Release();

        $jobExecution = new JobExecution('rsync', 'deploy', [
            'rsync_exclude' => [
            ],
            'rsync_before' => [
                'echo "Code will be deployed"'
            ],
            'rsync_after' => [
                'echo "Code is deployed"'
            ]
        ]);

        $properties = [
            'workspace_path' => '/tmp/1234'
        ];

        $platformConfig = [
            'remoteUser' => 'root',
            'remoteServer' => 'localhost',
            'remotePath' => '/',
            'environmentVariables' => [
                '$HOME' => '/'
            ]
        ];

        $this->configurator
            ->shouldReceive('__invoke')
            ->with($job)
            ->andReturn($platformConfig);

        $this->verifier
            ->shouldReceive('__invoke')
            ->with('root', 'localhost', '/')
            ->andReturn(true);

        $this->runner
            ->shouldReceive('__invoke')
            ->with('root', 'localhost', '/', ['echo "Code will be deployed"'], ['$HOME' => '/'])
            ->andReturn(true);

        $this->deployer
            ->shouldReceive('__invoke')
            ->with('/tmp/1234/workspace', 'root', 'localhost', '/', [])
            ->andReturn(true);

        $this->runner
            ->shouldReceive('__invoke')
            ->with('root', 'localhost', '/', ['echo "Code is deployed"'], ['$HOME' => '/'])
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any());

        $platform = new RsyncDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->verifier,
            $this->runner,
            $this->deployer
        );

        $actual = $platform($job, $jobExecution, $properties);
        $this->assertSame(false, $actual);
    }
}
