<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\Rsync\Steps;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\SSHSessionManager;
use Hal\Agent\Remoting\SSHProcess;
use Hal\Agent\Remoting\CommandContext;

class VerifierTest extends MockeryTestCase
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
            ->with('failure', Mockery::type('string'), Mockery::type('array'))
            ->once();

        $action = new Verifier($this->logger, $this->ssh, $this->remoter);
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
            ->with($this->context, [], [true, Verifier::CREATE_DIR])
            ->andReturn(false)
            ->once();

        $action = new Verifier($this->logger, $this->ssh, $this->remoter);
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
            ->with('failure', Mockery::type('string'), Mockery::type('array'))
            ->once();

        $action = new Verifier($this->logger, $this->ssh, $this->remoter);
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
            ->with('sshuser', 'hostname', 'find "/var/test" -prune -user "sshuser" -type d')
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
            ->with('failure', Mockery::type('string'), Mockery::type('array'))
            ->once();

        $action = new Verifier($this->logger, $this->ssh, $this->remoter);
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
            ->with('sshuser', 'hostname', 'find "/var/test" -prune -user "sshuser" -type d')
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
            ->with('failure', Mockery::type('string'), Mockery::type('array'))
            ->once();

        $action = new Verifier($this->logger, $this->ssh, $this->remoter);
        $success = $action('sshuser', 'hostname', '/var/test');

        $this->assertFalse($success);
    }
}
