<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Runner;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Deploy\Artifacter;
use Hal\Agent\Deploy\Resolver;
use Hal\Agent\JobRunner;
use Hal\Agent\Job\LocalCleaner;
use Hal\Agent\JobConfiguration\ConfigurationReader;
use Hal\Agent\Testing\IOTestCase;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

class DeployCommandTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $cleaner;

    public $resolver;
    public $artifacter;
    public $reader;

    public $deployer;
    private $builder;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->cleaner = Mockery::mock(LocalCleaner::class);

        $this->resolver = Mockery::mock(Resolver::class);
        $this->artifacter = Mockery::mock(Artifacter::class);
        $this->reader = Mockery::mock(ConfigurationReader::class);

        $this->builder = Mockery::mock(JobRunner::class);
        $this->deployer = Mockery::mock(JobRunner::class);
    }

    public function configureCommand($c)
    {
        DeployCommand::configure($c);
    }

    public function testSuccess()
    {
        $release = $this->generateMockRelease();

        $properties = [
            'job'  => $release,
            'platform' => 'script',

            'default_configuration' => [
                'platform' => 'linux',
                'image' => 'default',
                'build' => [],
            ],
            'workspace_path' => '/path/to/job-1234',
            'artifact_stored_file' => '/artifacts/job-1234.tgz',

            'encrypted_sources' => []
        ];

        $config = [
            'platform' => 'linux',
            'image' => 'my-project-image:latest',
            'env' => [
                'global' => ['TEST_VAR' => '1234']
            ],
            'build_transform' => ['transform_step1 --flag', 'path/to/step2 arg1'],
            'before_deploy' => ['before_step1 --flag', 'path/to/step2 arg21'],
            'deploy' => ['deploy_step1 --flag', 'path/to/step2 arg3'],
            'after_deploy' => ['after_step1 --flag', 'path/to/step2 arg4'],
        ];

        $this->resolver
            ->shouldReceive('__invoke')
            ->with('1')
            ->andReturn($properties);

        $this->artifacter
            ->shouldReceive('__invoke')
            ->with('/path/to/job-1234/job', '/path/to/job-1234/artifact.tgz', '/artifacts/job-1234.tgz')
            ->andReturn(true);

        $this->reader
            ->shouldReceive('__invoke')
            ->with('/path/to/job-1234/job', [
                'platform' => 'linux',
                'image' => 'default',
                'build' => [],
            ])
            ->andReturn($config);

        $executions = [];
        $with = Mockery::on(function($v) use (&$executions) {
            $executions[] = $v;
            return true;
        });

        $this->builder
            ->shouldReceive('__invoke')
            ->with($release, Mockery::any(), $with, $properties)
            ->times(3)
            ->andReturn(true);

        $this->deployer
            ->shouldReceive('__invoke')
            ->with($release, Mockery::any(), $with, $properties)
            ->once()
            ->andReturn(true);

        $this->cleaner
            ->shouldReceive('__invoke')
            ->with(['/path/to/job-1234'])
            ->andReturn(true);

        $this->logger
            ->shouldReceive('start')
            ->with($release)
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Resolved deployment configuration', Mockery::any())
            ->once();
        $this->logger
            ->shouldReceive('setStage')
            ->times(2);
        $this->logger
            ->shouldReceive('success')
            ->once();
        $this->logger
            ->shouldReceive('failure')
            ->never();

        $command = new DeployCommand(
            $this->logger,
            $this->cleaner,
            $this->resolver,
            $this->artifacter,
            $this->reader,
            $this->builder,
            $this->deployer
        );

        $io = $this->ioForCommand('configureCommand', [
            'RELEASE_ID' => '1'
        ]);
        $exit = $command->execute($io);

        $expected = [
            'Runner - Deploy release',

            '[1/7] Resolving configuration',
            ' * Release: 5678',
            ' * Build: 1234',
            ' * Application: derp (ID: a-1234)',
            ' * Environment: staging (ID: e-1234)',

            '[2/7] Downloading build artifact',
            ' * Release Workspace: /path/to/job-1234',
            ' * Artifact Repository: Filesystem',
            ' * Repository Location: /artifacts/job-1234.tgz',

            '[3/7] Reading .hal.yml configuration',
            'Application configuration:',
            '  platform          "linux"',
            '  image             "my-project-image:latest"',

            '[4/7] Running build transform stage',
            'Running steps:',
            ' * transform_step1 --flag',
            ' * path/to/step2 arg1',

            '[5/7] Running before deployment stage',
            'Running steps:',
            ' * before_step1 --flag',
            ' * path/to/step2 arg1',

            '[6/7] Running deployment stage',
            ' * Platform: script',

            '[7/7] Running after deployment stage',
            'Running steps:',
            ' * after_step1 --flag',
            ' * path/to/step2 arg1',

            'Release clean-up',
            'Release artifacts to remove:',
            ' * /path/to/job-1234',

            '[OK] Release was deployed successfully.'
        ];

        $this->assertCount(4, $executions);

        $this->assertSame($executions[0]->platform(), 'linux');
        $this->assertSame($executions[0]->stage(), 'build_transform');
        $this->assertSame($executions[0]->config(), $config);

        $config['env']['global']['HAL_DEPLOY_STATUS'] = 'pending';
        $this->assertSame($executions[1]->platform(), 'linux');
        $this->assertSame($executions[1]->stage(), 'before_deploy');
        $this->assertSame($executions[1]->config(), $config);

        $config['env']['global']['HAL_DEPLOY_STATUS'] = 'running';
        $this->assertSame($executions[2]->platform(), 'script');
        $this->assertSame($executions[2]->stage(), 'deploy');
        $this->assertSame($executions[2]->config(), $config);

        $config['env']['global']['HAL_DEPLOY_STATUS'] = 'success';
        $this->assertSame($executions[3]->platform(), 'linux');
        $this->assertSame($executions[3]->stage(), 'after_deploy');
        $this->assertSame($executions[3]->config(), $config);

        $this->assertContainsLines($expected, $this->output());
    }

    public function testReleaseResolvingFails()
    {
        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([]);

        $this->logger
            ->shouldReceive('setStage')
            ->with('created')
            ->once();
        $this->logger
            ->shouldReceive('failure')
            ->once();

        $command = new DeployCommand(
            $this->logger,
            $this->cleaner,
            $this->resolver,
            $this->artifacter,
            $this->reader,
            $this->builder,
            $this->deployer
        );

        $io = $this->ioForCommand('configureCommand', [
            'RELEASE_ID' => '1'
        ]);
        $exit = $command->execute($io);

        $expected = [
            'Runner - Deploy release',

            '[1/7] Resolving configuration',
            '[ERROR] Release cannot be run.'
        ];

        $this->assertContainsLines($expected, $this->output());
    }

    public function testDeployFailsButAfterDeployKeepsGoing()
    {
        $release = $this->generateMockRelease();

        $properties = [
            'job'  => $release,
            'platform' => 'script',

            'default_configuration' => [],
            'workspace_path' => '',
            'artifact_stored_file' => ''
        ];

        $config = [
            'platform' => 'linux',
            'image' => 'my-project-image:latest',
            'env' => [
                'global' => ['TEST_VAR' => '1234']
            ],
            'build_transform' => ['transform_step1 --flag', 'path/to/step2 arg1'],
            'before_deploy' => ['before_step1 --flag', 'path/to/step2 arg21'],
            'deploy' => ['deploy_step1 --flag', 'path/to/step2 arg3'],
            'after_deploy' => ['after_step1 --flag', 'path/to/step2 arg4'],
        ];

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn($properties);

        $this->artifacter
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->reader
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $executions = [];
        $with = Mockery::on(function($v) use (&$executions) {
            $executions[] = $v;
            return true;
        });

        $this->builder
            ->shouldReceive('__invoke')
            ->with($release, Mockery::any(), $with, Mockery::any())
            ->times(3)
            ->andReturn(true);

        $this->deployer
            ->shouldReceive('__invoke')
            ->with($release, Mockery::any(), $with, Mockery::any())
            ->once()
            ->andReturn(false);

        $this->cleaner
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->logger
            ->shouldReceive('start');
        $this->logger
            ->shouldReceive('event');
        $this->logger
            ->shouldReceive('setStage');
        $this->logger
            ->shouldReceive('success')
            ->never();
        $this->logger
            ->shouldReceive('failure')
            ->once();

        $command = new DeployCommand(
            $this->logger,
            $this->cleaner,
            $this->resolver,
            $this->artifacter,
            $this->reader,
            $this->builder,
            $this->deployer
        );

        $io = $this->ioForCommand('configureCommand', [
            'RELEASE_ID' => '1'
        ]);
        $exit = $command->execute($io);

        $expected = [
            'Runner - Deploy release',

            '[5/7] Running before deployment stage',
            'Running steps:',
            ' * before_step1 --flag',
            ' * path/to/step2 arg1',

            '[6/7] Running deployment stage',
            ' * Platform: script',

            '[7/7] Running after deployment stage',
            'Running steps:',
            ' * after_step1 --flag',
            ' * path/to/step2 arg1',

            '[ERROR] Deployment stage failed.'
        ];

        $this->assertCount(4, $executions);

        $this->assertSame($executions[0]->platform(), 'linux');
        $this->assertSame($executions[0]->stage(), 'build_transform');
        $this->assertSame($executions[0]->config(), $config);

        $config['env']['global']['HAL_DEPLOY_STATUS'] = 'pending';
        $this->assertSame($executions[1]->platform(), 'linux');
        $this->assertSame($executions[1]->stage(), 'before_deploy');
        $this->assertSame($executions[1]->config(), $config);

        $config['env']['global']['HAL_DEPLOY_STATUS'] = 'running';
        $this->assertSame($executions[2]->platform(), 'script');
        $this->assertSame($executions[2]->stage(), 'deploy');
        $this->assertSame($executions[2]->config(), $config);

        $config['env']['global']['HAL_DEPLOY_STATUS'] = 'failure';
        $this->assertSame($executions[3]->platform(), 'linux');
        $this->assertSame($executions[3]->stage(), 'after_deploy');
        $this->assertSame($executions[3]->config(), $config);

        $this->assertContainsLines($expected, $this->output());
    }

    public function generateMockRelease()
    {
        $build = $this->generateMockBuild();

        return (new Release('5678'))
            ->withBuild($build)
            ->withApplication($build->application())
            ->withEnvironment($build->environment())
            ->withTarget(
                (new Target('', 't-1234'))
                    ->withName('deploy')
            );
    }

    public function generateMockBuild()
    {
        return (new Build('1234'))
            ->withReference('master')
            ->withCommit('7de49f3')
            ->withApplication(
                (new Application('a-1234'))
                    ->withName('derp')
            )
            ->withEnvironment(
                (new Environment('e-1234'))
                    ->withName('staging')
            );
    }
}
