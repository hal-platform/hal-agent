<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\EC2;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Remoting\FileSyncManager;
use Symfony\Component\Process\Process;

class PusherTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $fileSync;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->fileSync = Mockery::mock(FileSyncManager::class);
    }

    public function testSuccess()
    {
        $process = Mockery::mock(Process::class, [
            'run' => 0,
            'getCommandLine' => 'rsync',
            'isSuccessful' => true
        ])->makePartial();

        $this->fileSync
            ->shouldReceive('buildOutgoingRsync')
            ->with('build/path', 'ec2_user', Mockery::type('string'), 'sync/path', [])
            ->andReturn([
                'rsync',
                '--perms',
                'fromhere',
                'user@tohere'
            ])
            ->times(3);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process)
            ->times(3);

        $this->logger
            ->shouldReceive('event')
            ->with('success', Pusher::EVENT_MESSAGE, [
                'instances' => [
                    [
                        'ID' => '1',
                        'public DNS name' => '127.0.0.1',
                        'status' => 'success'
                    ],
                    [
                        'ID' => '2',
                        'public DNS name' => '127.0.0.2',
                        'status' => 'success'
                    ],
                    [
                        'ID' => '3',
                        'public DNS name' => '127.0.0.3',
                        'status' => 'success'
                    ]
                ],
                'success' => 3,
                'failure' => 0
            ])->once();

        $action = new Pusher($this->logger, $this->fileSync, $builder, 20);

        $instances = [
            [
                'InstanceId' => '1',
                'PublicDnsName' => '127.0.0.1',
            ],
            [
                'InstanceId' => '2',
                'PublicDnsName' => '127.0.0.2',
            ],
            [
                'InstanceId' => '3',
                'PublicDnsName' => '127.0.0.3',
            ]
        ];

        $success = $action('build/path', 'ec2_user', 'sync/path', [], $instances);
        $this->assertSame(true, $success);
    }

    public function testSomeFailed()
    {
        $process = Mockery::mock(Process::class, [
            'run' => 0,
            'getCommandLine' => 'rsync'
        ])->makePartial();

        $process
            ->shouldReceive('isSuccessful')
            ->andReturn(false)
            ->once();
        $process
            ->shouldReceive('isSuccessful')
            ->andReturn(true)
            ->once();
        $process
            ->shouldReceive('isSuccessful')
            ->andReturn(false)
            ->once();

        $this->fileSync
            ->shouldReceive('buildOutgoingRsync')
            ->with('build/path', 'ec2_user', Mockery::type('string'), 'sync/path', ['exclude_file', 'exclude_dir/'])
            ->andReturn([
                'rsync',
                '--perms',
                'fromhere',
                'user@tohere'
            ])
            ->times(3);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process)
            ->times(3);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Pusher::EVENT_MESSAGE, [
                'instances' => [
                    [
                        'ID' => '1',
                        'public DNS name' => '127.0.0.1',
                        'status' => 'failure'
                    ],
                    [
                        'ID' => '2',
                        'public DNS name' => '127.0.0.2',
                        'status' => 'success'
                    ],
                    [
                        'ID' => '3',
                        'public DNS name' => '127.0.0.3',
                        'status' => 'failure'
                    ]
                ],
                'success' => 1,
                'failure' => 2
            ])->once();

        $action = new Pusher($this->logger, $this->fileSync, $builder, 20);

        $instances = [
            [
                'InstanceId' => '1',
                'PublicDnsName' => '127.0.0.1',
            ],
            [
                'InstanceId' => '2',
                'PublicDnsName' => '127.0.0.2',
            ],
            [
                'InstanceId' => '3',
                'PublicDnsName' => '127.0.0.3',
            ]
        ];

        $success = $action('build/path', 'ec2_user', 'sync/path', ['exclude_file', 'exclude_dir/'], $instances);
        $this->assertSame(false, $success);
    }
}
