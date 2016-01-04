<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\EC2;

use Mockery;
use PHPUnit_Framework_TestCase;

class PusherTest extends PHPUnit_Framework_TestCase
{
    public $logger;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
    }

    public function testSuccess()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'getCommandLine' => 'rsync',
            'isSuccessful' => true
        ])->makePartial();

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

        $action = new Pusher($this->logger, $builder, 'ec2-user', 20);

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

        $success = $action('build/path', 'sync/path', [], $instances);
        $this->assertSame(true, $success);
    }

    public function testSomeFailed()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
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

        $action = new Pusher($this->logger, $builder, 'ec2-user', 20);

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

        $success = $action('build/path', 'sync/path', [], $instances);
        $this->assertSame(false, $success);
    }
}
