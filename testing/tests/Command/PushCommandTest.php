<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Exception;
use Mockery;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class PushCommandTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $resolver;
    public $mover;
    public $unpacker;
    public $reader;
    public $deployer;

    public $filesystem;
    public $ghDeploymenter;

    public $input;
    public $output;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->resolver = Mockery::mock('QL\Hal\Agent\Push\Resolver');
        $this->mover = Mockery::mock('QL\Hal\Agent\Push\Mover');
        $this->unpacker = Mockery::mock('QL\Hal\Agent\Push\Unpacker');
        $this->reader = Mockery::mock('QL\Hal\Agent\Build\ConfigurationReader');
        $this->builder = Mockery::mock('QL\Hal\Agent\Build\DelegatingBuilder');
        $this->deployer = Mockery::mock('QL\Hal\Agent\Push\DelegatingDeployer');
        $this->filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');
        $this->ghDeploymenter = Mockery::mock('QL\Hal\Agent\Utility\GithubDeploymenter');

        $this->output = new BufferedOutput;
    }

    public function testBuildResolvingFails()
    {
        $this->input = new ArrayInput([
            'PUSH_ID' => '1'
        ]);

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturnNull();

        $this->logger
            ->shouldReceive('failure')
            ->twice();
        $this->logger
            ->shouldReceive('setStage')
            ->once();
        $this->logger
            ->shouldReceive('addSubscription')
            ->twice();

        $this->ghDeploymenter
            ->shouldReceive('updateDeployment')
            ->with('failure')
            ->twice();

        $command = new PushCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->mover,
            $this->unpacker,
            $this->reader,
            $this->builder,
            $this->deployer,
            $this->filesystem,
            $this->ghDeploymenter
        );

        $command->disableShutdownHandler();
        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Resolving push properties
Push details could not be resolved.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testSuccess()
    {
        $this->input = new ArrayInput([
            'PUSH_ID' => '1'
        ]);

        $push = Mockery::mock('QL\Hal\Core\Entity\Push', [
            'getStatus' => null,
            'setStatus' => null,
            'setStart' => null,
            'setEnd' => null,
            'getDeployment' => Mockery::mock('QL\Hal\Core\Entity\Deployment', [
                'getServer' => Mockery::mock('QL\Hal\Core\Entity\Server', [
                    'getEnvironment' => Mockery::mock('QL\Hal\Core\Entity\Environment', [
                        'getKey' => null
                    ]),
                    'getName' => null
                ]),
                'getRepository' => Mockery::mock('QL\Hal\Core\Entity\Repository', [
                    'getKey' => null
                ])
            ]),
            'getId' => 1234
        ]);

        $this->logger
            ->shouldReceive('start')
            ->once();
        $this->logger
            ->shouldReceive('success')
            ->once();
        $this->logger
            ->shouldReceive('failure')
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->once();
        $this->logger
            ->shouldReceive('setStage')
            ->twice();
        $this->logger
            ->shouldReceive('addSubscription')
            ->with('push.failure', 'notifier.email')
            ->once();
        $this->logger
            ->shouldReceive('addSubscription')
            ->with('push.success', 'notifier.email')
            ->once();

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'push' => $push,
                'method' => 'rsync',

                'configuration' => [
                    'system' => 'unix',
                    'build_transform' => [
                        'cmd'
                    ]
                ],

                'location' => [
                    'path' => 'path/dir',
                    'archive' => 'path/file',
                    'tempArchive' => 'path/file2',
                ],

                'pushProperties' => [],
                'artifacts' => [
                    'path/dir',
                    'path/file2'
                ]
            ]);
        $this->mover
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->unpacker
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->reader
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->deployer
            ->shouldReceive('__invoke')
            ->andReturn(true);

        // cleanup
        $this->filesystem
            ->shouldReceive('remove')
            ->twice();

        $this->ghDeploymenter
            ->shouldReceive('createGitHubDeployment')
            ->once();
        $this->ghDeploymenter
            ->shouldReceive('updateDeployment')
            ->with('pending')
            ->once();
        $this->ghDeploymenter
            ->shouldReceive('updateDeployment')
            ->with('success')
            ->once();
        $this->ghDeploymenter
            ->shouldReceive('updateDeployment')
            ->with('failure')
            ->once();

        $command = new PushCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->mover,
            $this->unpacker,
            $this->reader,
            $this->builder,
            $this->deployer,
            $this->filesystem,
            $this->ghDeploymenter
        );

        $command->disableShutdownHandler();
        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Resolving push properties
Found push: 1234
Moving archive to local storage
Unpacking build archive
Reading .hal9000.yml
Running build transform command
Deploying application
Success!

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testEmergencyErrorHandling()
    {
        $this->input = new ArrayInput([
            'PUSH_ID' => '1'
        ]);

        $push = Mockery::mock('QL\Hal\Core\Entity\Push', [
            'getStatus' => 'Pushing',
            'setStart' => null,
            'getDeployment' => Mockery::mock('QL\Hal\Core\Entity\Deployment', [
                'getServer' => Mockery::mock('QL\Hal\Core\Entity\Server', [
                    'getEnvironment' => Mockery::mock('QL\Hal\Core\Entity\Environment', [
                        'getKey' => null
                    ]),
                    'getName' => null
                ]),
                'getRepository' => Mockery::mock('QL\Hal\Core\Entity\Repository', [
                    'getKey' => null
                ])
            ]),
            'getId' => 1234
        ]);

        $this->logger
            ->shouldReceive('start')
            ->once();
        $this->logger
            ->shouldReceive('success')
            ->never();
        $this->logger
            ->shouldReceive('failure')
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->once();
        $this->logger
            ->shouldReceive('setStage')
            ->once();
        $this->logger
            ->shouldReceive('addSubscription')
            ->twice();

        $this->ghDeploymenter
            ->shouldReceive('updateDeployment')
            ->with('failure')
            ->once();

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'push' => $push,
                'method' => 'rsync',

                'configuration' => [],

                'location' => [
                    'path' => 'path/dir',
                    'archive' => 'path/file',
                    'tempArchive' => 'path/file2',
                ],

                'pushProperties' => [],
                'artifacts' => []
            ]);

        $this->mover->shouldReceive(['__invoke' => true]);
        $this->unpacker->shouldReceive(['__invoke' => true]);
        $this->reader->shouldReceive(['__invoke' => true]);

        // simulate an error
        $this->deployer
            ->shouldReceive('__invoke')
            ->andThrow(new Exception);

        $command = new PushCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->mover,
            $this->unpacker,
            $this->reader,
            $this->builder,
            $this->deployer,
            $this->filesystem,
            $this->ghDeploymenter
        );

        try {
            $command->disableShutdownHandler();
            $command->run($this->input, $this->output);
        } catch (Exception $e) {}

        // this will call __destruct
        unset($command);
    }
}
