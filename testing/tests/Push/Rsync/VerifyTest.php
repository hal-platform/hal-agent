<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\Rsync;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Remoting\SSHSessionManager;
use QL\Hal\Agent\Remoting\SSHProcess;
use QL\Hal\Agent\Remoting\CommandContext;

class VerifyTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $ssh;
    public $remoter;
    public $context;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->ssh = Mockery::mock(SSHSessionManager::class, [
            'getErrors' => ['ssh error'],
            'createSession' => true
        ]);

        $this->remoter = Mockery::mock(SSHProcess::class);
        $this->context = Mockery::mock(CommandContext::class);
    }

    public function testSSHTestFailsReturnsFalse()
    {
        $this->ssh
            ->shouldReceive('createSession')
            ->with('sshuser', 'hostname')
            ->andReturnNull()
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Verify::EVENT_MESSAGE, ['errors' => ['ssh error']])
            ->once();

        $action = new Verify($this->logger, $this->ssh, $this->remoter);
        $success = $action('sshuser', 'hostname', 'path');

        $this->assertFalse($success);
    }

    public function testTargetDoesNotExistAndCannotBeCreatedReturnsFalse()
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'hostname', 'test -d "path"')
            ->andReturn($this->context)
            ->once();
        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'hostname', 'mkdir "path"')
            ->andReturn($this->context)
            ->once();

        $this->remoter
            ->shouldReceive('run')
            ->with($this->context, [], [false])
            ->andReturn(false)
            ->once();
        $this->remoter
            ->shouldReceive('run')
            ->with($this->context, [], [true, Verify::CREATE_DIR])
            ->andReturn(false)
            ->once();

        $action = new Verify($this->logger, $this->ssh, $this->remoter);
        $success = $action('sshuser', 'hostname', 'path');

        $this->assertFalse($success);
    }

    public function testTargetDirExistsButPermissionsCannotBeRead()
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'hostname', 'test -d "path"')
            ->andReturn($this->context)
            ->once();
        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'hostname', 'test -w "path"')
            ->andReturn($this->context)
            ->once();
        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'hostname', 'ls -ld "path"')
            ->andReturn($this->context)
            ->once();

        // dir exists
        // is writeable
        // dir permissions
        $this->remoter
            ->shouldReceive('run')
            ->andReturn(true, true, false)
            ->times(3);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Verify::ERR_READ_PERMISSIONS, ['directory' => 'path'])
            ->once();

        $action = new Verify($this->logger, $this->ssh, $this->remoter);
        $success = $action('sshuser', 'hostname', 'path');

        $this->assertFalse($success);
    }

    public function testTargetDirExistsButIsNotWriteable()
    {
        $lsOutput = <<<SHELL_OUTPUT
drwxrwxr-x. 10 sshuser users 4096 Jan  5 15:38 /var/test/
SHELL_OUTPUT;

        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'hostname', 'test -d "/var/test"')
            ->andReturn($this->context)
            ->once();
        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'hostname', 'test -w "/var/test"')
            ->andReturn($this->context)
            ->once();
        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'hostname', 'ls -ld "/var/test"')
            ->andReturn($this->context)
            ->once();
            $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'hostname', 'find "/var/test" -maxdepth 0 -user "sshuser" -type d -print0')
            ->andReturn($this->context)
            ->once();

        // dir exists,
        // is not writeable
        // owned
        $this->remoter
            ->shouldReceive('run')
            ->andReturn(true, false, true, true)
            ->times(4);

        $this->remoter
            ->shouldReceive('getLastOutput')
            ->andReturn($lsOutput);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Verify::ERR_VERIFY_PERMISSIONS, [
                'directory' => '/var/test',
                'currentPermissions' => $lsOutput,
                'requiredOwner' => 'sshuser',
                'isWriteable' => 'No',
            ])
            ->once();

        $action = new Verify($this->logger, $this->ssh, $this->remoter);
        $success = $action('sshuser', 'hostname', '/var/test');

        $this->assertFalse($success);
    }

    public function testTargetDirExistsButIsNotOwned()
    {
        $lsOutput = <<<SHELL_OUTPUT
drwxrwxr-x. 10 otheruser users 4096 Jan  5 15:38 /var/test/
SHELL_OUTPUT;

        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'hostname', 'test -d "/var/test"')
            ->andReturn($this->context)
            ->once();
        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'hostname', 'test -w "/var/test"')
            ->andReturn($this->context)
            ->once();
        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'hostname', 'ls -ld "/var/test"')
            ->andReturn($this->context)
            ->once();
        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'hostname', 'find "/var/test" -maxdepth 0 -user "sshuser" -type d -print0')
            ->andReturn($this->context)
            ->once();

        // dir exists,
        // is writeable
        // not owned
        $this->remoter
            ->shouldReceive('run')
            ->andReturn(true, true, true, false)
            ->times(4);

        $this->remoter
            ->shouldReceive('getLastOutput')
            ->andReturn($lsOutput);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Verify::ERR_VERIFY_PERMISSIONS, [
                'directory' => '/var/test',
                'currentPermissions' => $lsOutput,
                'requiredOwner' => 'sshuser',
                'isWriteable' => 'Yes',
            ])
            ->once();

        $action = new Verify($this->logger, $this->ssh, $this->remoter);
        $success = $action('sshuser', 'hostname', '/var/test');

        $this->assertFalse($success);
    }
}
