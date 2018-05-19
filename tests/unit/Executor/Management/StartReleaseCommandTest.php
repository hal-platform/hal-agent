<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Management;

use Doctrine\ORM\EntityManager;
use Hal\Agent\Application\HalClient;
use Hal\Agent\Executor\Runner\DeployCommand;
use Hal\Agent\Testing\IOTestCase;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\Target;
use Hal\Core\Repository\BuildRepository;
use Hal\Core\Repository\TargetRepository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class StartReleaseCommandTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $em;
    public $buildRepo;
    public $targetRepo;
    public $runner;
    public $api;

    public function setUp()
    {
        $this->em = Mockery::mock(EntityManager::class);
        $this->buildRepo = Mockery::mock(BuildRepository::class);
        $this->targetRepo = Mockery::mock(TargetRepository::class);

        $this->em
            ->shouldReceive('getRepository')
            ->with(Build::class)
            ->andReturn($this->buildRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(Target::class)
            ->andReturn($this->targetRepo);

        $this->runner = Mockery::mock(DeployCommand::class);
        $this->api = Mockery::mock(HalClient::class);
    }

    public function configureCommand($c)
    {
        StartReleaseCommand::configure($c);
    }

    public function testSuccess()
    {
        $application = new Application('1');

        $target = new Target('', 'c22c8ff0a11c47fd9199a6170c6b643e');

        $apiDeployResponse = [
            '_embedded' => [
                'releases' => [
                    [
                        '_links' => [
                            'page' => ['href' => 'https://hal.example.com/releases/1234'],
                            'target' => ['title' => 'Target Name 1'],
                            'application' => ['title' => 'Demo App 1'],
                            'environment' => ['title' => 'prod']
                        ],

                        'id' => '1234',

                        '_embedded' => [
                            'build' => [
                                '_links' => [
                                    'page' => ['href' => 'https://hal.example.com/builds/5678'],
                                    'github_reference_page' => ['href' => 'https://git.example.com/project']
                                ],

                                'id' => '5678',
                                'reference' => 'master',
                                'commit' => 'ff2f1dde7eb6c9f0b5c478765bd7c1bb9ac29adb',
                            ]
                        ]
                    ],
                ]
            ]
        ];

        $this->targetRepo
            ->shouldReceive('find')
            ->andReturn($target);

        $this->api
            ->shouldReceive('createRelease')
            ->with('1', 'c22c8ff0a11c47fd9199a6170c6b643e')
            ->andReturn($apiDeployResponse);

        $this->runner
            ->shouldReceive('execute')
            ->andReturn(0);

        $command = new StartReleaseCommand($this->em, $this->runner, $this->api);

        $io = $this->ioForCommand('configureCommand', [
            'BUILD_ID' => '1',
            'TARGET' => 'c22c8ff0a11c47fd9199a6170c6b643e'
        ]);
        $exit = $command->execute($io);

        $expected = [
            'Create a release and run the deployment',

            'Details',
            ' * Application: Demo App 1',
            ' * Environment: prod',

            'Build Information',
            ' * ID: 5678',
            ' * URL: https://hal.example.com/builds/5678',
            ' * VCS: https://git.example.com/project',
            ' * Reference: master (ff2f1dde7eb6c9f0b5c478765bd7c1bb9ac29adb)',

            'Release Information',
            ' * ID: 1234',
            ' * URL: https://hal.example.com/releases/1234',
            ' * Target: Target Name 1',

            '[OK] Release created.',
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(0, $exit);
    }

    public function testAPIError()
    {
        $target = new Target('', 'c22c8ff0a11c47fd9199a6170c6b643e');

        $this->targetRepo
            ->shouldReceive('find')
            ->andReturn($target);

        $this->api
            ->shouldReceive('createRelease')
            ->with('1', 'c22c8ff0a11c47fd9199a6170c6b643e')
            ->andReturnNull();
        $this->api
            ->shouldReceive('apiErrors')
            ->andReturn(['error 1', 'error 2']);

        $command = new StartReleaseCommand($this->em, $this->runner, $this->api);

        $io = $this->ioForCommand('configureCommand', [
            'BUILD_ID' => '1',
            'TARGET' => 'c22c8ff0a11c47fd9199a6170c6b643e'
        ]);
        $exit = $command->execute($io);

        $expected = [
            '[ERROR] An error was returned from the API.',
            '[ERROR] error 1',
            '        error 2'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }

    public function testBuildNotFound()
    {
        $this->targetRepo
            ->shouldReceive('find')
            ->once()
            ->andReturnNull();

        $this->buildRepo
            ->shouldReceive('find')
            ->once()
            ->andReturnNull();

        $command = new StartReleaseCommand($this->em, $this->runner, $this->api);

        $io = $this->ioForCommand('configureCommand', [
            'BUILD_ID' => 'dc8dd7ba76424de18ea23eb959823c48',
            'TARGET' => 'c22c8ff0a11c47fd9199a6170c6b643e'
        ]);
        $exit = $command->execute($io);

        $expected = [
            '[ERROR] Target not found.'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }

    public function testTargetNameNotFound()
    {
        $application = new Application;
        $build = (new Build)
            ->withApplication($application);

        $this->targetRepo
            ->shouldReceive('find')
            ->never();

        $this->buildRepo
            ->shouldReceive('find')
            ->andReturn($build);

        $this->targetRepo
            ->shouldReceive('findBy')
            ->with(['name' => 'target_name', 'application' => $application])
            ->andReturnNull();

        $command = new StartReleaseCommand($this->em, $this->runner, $this->api);

        $io = $this->ioForCommand('configureCommand', [
            'BUILD_ID' => 'dc8dd7ba76424de18ea23eb959823c48',
            'TARGET' => 'target_name'
        ]);
        $exit = $command->execute($io);

        $expected = [
            '[ERROR] Target not found.'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }
}
