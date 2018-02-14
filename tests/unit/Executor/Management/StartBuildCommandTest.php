<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Management;

use Doctrine\ORM\EntityManager;
use Hal\Agent\Application\HalClient;
use Hal\Agent\Executor\Runner\BuildCommand;
use Hal\Agent\Testing\IOTestCase;
use Hal\Core\Entity\Application;
use Hal\Core\Repository\ApplicationRepository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class StartBuildCommandTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $em;
    public $applicationRepo;
    public $runner;
    public $api;

    public function setUp()
    {
        $this->applicationRepo = Mockery::mock(ApplicationRepository::class);
        $this->em = Mockery::mock(EntityManager::class, [
            'getRepository' => $this->applicationRepo
        ]);

        $this->runner = Mockery::mock(BuildCommand::class);
        $this->api = Mockery::mock(HalClient::class);
    }

    public function configureCommand($c)
    {
        StartBuildCommand::configure($c);
    }

    public function testSuccess()
    {
        $application = new Application('1');

        $apiBuildResponse = [
            '_links' => [
                'page' => ['href' => 'http://hal.example.com/builds/1234']
            ],

            'id' => '1234',
            'reference' => 'master',
            'commit' => 'ff2f1dde7eb6c9f0b5c478765bd7c1bb9ac29adb',

            '_embedded' => [
                'application' => [
                    '_links' => [
                        'vcs_provider' => ['title' => 'GitHub Enterprise']
                    ],
                    'name' => 'Demo App 1'
                ],
                'environment' => [
                    'name' => 'prod'
                ],
            ]
        ];

        $this->applicationRepo
            ->shouldReceive('findOneBy')
            ->with(['name' => '1'])
            ->andReturn($application);

        $this->api
            ->shouldReceive('createBuild')
            ->with('1', '2', '3')
            ->andReturn($apiBuildResponse);

        $this->runner
            ->shouldReceive('execute')
            ->andReturn(0);

        $command = new StartBuildCommand($this->em, $this->runner, $this->api);

        $io = $this->ioForCommand('configureCommand', [
            'APPLICATION' => '1',
            'ENVIRONMENT' => '2',
            'VCS_REFERENCE' => '3'
        ]);
        $exit = $command->execute($io);

        $expected = [
            'Create and run a build',

            'Details',
            ' * Application: Demo App 1',
            ' * Environment: prod',

            'Build Information',
            ' * ID: 1234',
            ' * URL: http://hal.example.com/builds/1234',
            ' * VCS: GitHub Enterprise',
            ' * Reference: master (ff2f1dde7eb6c9f0b5c478765bd7c1bb9ac29adb)',

            '[OK] Build created.',
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(0, $exit);
    }

    public function testAPIError()
    {
        $application = new Application('1');

        $this->applicationRepo
            ->shouldReceive('findOneBy')
            ->with(['name' => '1'])
            ->andReturn($application);

        $this->api
            ->shouldReceive('createBuild')
            ->with('1', '2', '3')
            ->andReturnNull();
        $this->api
            ->shouldReceive('apiErrors')
            ->andReturn(['error 1', 'error 2']);

        $command = new StartBuildCommand($this->em, $this->runner, $this->api);

        $io = $this->ioForCommand('configureCommand', [
            'APPLICATION' => '1',
            'ENVIRONMENT' => '2',
            'VCS_REFERENCE' => '3'
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

    public function testApplicationNameNotFound()
    {
        $this->applicationRepo
            ->shouldReceive('find')
            ->never();

        $this->applicationRepo
            ->shouldReceive('findOneBy')
            ->with(['name' => '1'])
            ->andReturnNull();

        $command = new StartBuildCommand($this->em, $this->runner, $this->api);

        $io = $this->ioForCommand('configureCommand', [
            'APPLICATION' => '1',
            'ENVIRONMENT' => '2',
            'VCS_REFERENCE' => '3'
        ]);
        $exit = $command->execute($io);

        $expected = [
            '[ERROR] Application not found.'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }

    public function testApplicationIDNotFound()
    {
        $this->applicationRepo
            ->shouldReceive('find')
            ->with('dc8dd7ba76424de18ea23eb959823c48')
            ->andReturnNull();

        $this->applicationRepo
            ->shouldReceive('findOneBy')
            ->with(['name' => 'dc8dd7ba76424de18ea23eb959823c48'])
            ->andReturnNull();

        $command = new StartBuildCommand($this->em, $this->runner, $this->api);

        $io = $this->ioForCommand('configureCommand', [
            'APPLICATION' => 'dc8dd7ba76424de18ea23eb959823c48',
            'ENVIRONMENT' => '2',
            'VCS_REFERENCE' => '3'
        ]);
        $exit = $command->execute($io);

        $expected = [
            '[ERROR] Application not found.'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }
}
