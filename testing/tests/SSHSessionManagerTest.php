<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent;

use Mockery;
use PHPUnit_Framework_TestCase;

class SSHSessionManagerTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $filesystem;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');
    }

    public function testNoCredentialsLogsError()
    {
        $expectedContext = [
            'user' => 'username',
            'server' => 'server'
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', SSHSessionManager::ERR_NO_CREDENTIALS, $expectedContext)
            ->once();

        $ssh = new SSHSessionManager($this->logger, $this->filesystem);
        $session = $ssh->createSession('username', 'server');

        $this->assertSame(null, $session);
    }

    public function testPrivateKeyMissingLogsError()
    {
        $expectedContext = [
            'user' => 'username',
            'server' => 'server'
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', SSHSessionManager::ERR_MISSING_PRIVATE_KEY, $expectedContext)
            ->once();

        $this->filesystem
            ->shouldReceive('exists')
            ->with('key/path')
            ->andReturn(false);

        $credentials = [
            ['username', 'server', 'key/path']
        ];

        $ssh = new SSHSessionManager($this->logger, $this->filesystem, $credentials);
        $session = $ssh->createSession('username', 'server');

        $this->assertSame(null, $session);
    }

    public function testPrivateKeyInvalidLogsError()
    {
        $expectedContext = [
            'user' => 'username',
            'server' => 'server'
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', SSHSessionManager::ERR_MISSING_PRIVATE_KEY, $expectedContext)
            ->once();

        $this->filesystem
            ->shouldReceive('exists')
            ->with('key/path')
            ->andReturn(true);

        $credentials = [
            ['username', 'server', 'key/path']
        ];

        $loader = function($filepath) {
            return '';
        };

        $ssh = new SSHSessionManager($this->logger, $this->filesystem, $credentials, $loader);
        $session = $ssh->createSession('username', 'server');

        $this->assertSame(null, $session);
    }

    public function testLoginFailureLogsError()
    {
        $expectedContext = [
            'user' => 'username123456789',
            'server' => 'localhost',
            'errors' => [
                'SSH_MSG_USERAUTH_FAILURE: publickey,keyboard-interactive'
            ]
        ];

        $context = null;
        $this->logger
            ->shouldReceive('event')
            ->with('failure', SSHSessionManager::ERR_CONNECT_SERVER, Mockery::on(function($v) use (&$context) {
                $context = $v;
                return true;
            }));

        $this->filesystem
            ->shouldReceive('exists')
            ->with('key/path')
            ->andReturn(true);

        $credentials = [
            ['username123456789', '*', 'key/path']
        ];

        $loader = function($filepath) {
            return $this->getSamplePrivateKeyForTesting();
        };

        $ssh = new SSHSessionManager($this->logger, $this->filesystem, $credentials, $loader);
        $session = $ssh->createSession('username123456789', 'localhost');

        $this->assertSame(null, $session);
        $this->assertSame($expectedContext, $context);
    }

    public function testLoginFailureForUnknownServer()
    {
        $expectedContext = [
            'user' => 'username123456789',
            'server' => 'server123456789',
            'errors' => [
                'SSH User Notice: Connection closed prematurely',
                'SSH Warning: fclose() expects parameter 1 to be resource, boolean given'
            ]
        ];

        $context = null;
        $this->logger
            ->shouldReceive('event')
            ->with('failure', SSHSessionManager::ERR_CONNECT_SERVER, Mockery::on(function($v) use (&$context) {
                $context = $v;
                return true;
            }));

        $this->filesystem
            ->shouldReceive('exists')
            ->with('key/path')
            ->andReturn(true);

        $credentials = [
            ['username123456789', '*', 'key/path']
        ];

        $loader = function($filepath) {
            return $this->getSamplePrivateKeyForTesting();
        };

        $ssh = new SSHSessionManager($this->logger, $this->filesystem, $credentials, $loader);
        $session = $ssh->createSession('username123456789', 'server123456789');

        $this->assertSame(null, $session);
        $this->assertSame($expectedContext, $context);
    }

    public function testServerSpecificCredentialsPreferredOverWildcard()
    {
        $expectedContext = [
            'user' => 'username',
            'server' => 'server'
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', SSHSessionManager::ERR_MISSING_PRIVATE_KEY, $expectedContext)
            ->once();

        // The important assertion for this test
        $this->filesystem
            ->shouldReceive('exists')
            ->with('key/path/2')
            ->andReturn(false);

        $credentials = [
            ['username', '*', 'key/path/1'],
            ['username', 'server', 'key/path/2'], // specific server pair will be picked over wildcard
            ['username', 'server', 'key/path/3'] // erroneous duplicate
        ];

        $ssh = new SSHSessionManager($this->logger, $this->filesystem, $credentials);
        $session = $ssh->createSession('username', 'server');

        $this->assertSame(null, $session);
    }

    public function testFirstCredentialIsPreferredIfDuplicatesSet()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', SSHSessionManager::ERR_MISSING_PRIVATE_KEY, Mockery::any())
            ->once();

        // The important assertion for this test
        $this->filesystem
            ->shouldReceive('exists')
            ->with('key/path/1')
            ->andReturn(false);

        $credentials = [
            ['username', 'server', 'key/path/1'],
            ['username', 'server', 'key/path/2']
        ];

        $ssh = new SSHSessionManager($this->logger, $this->filesystem, $credentials);
        $session = $ssh->createSession('username', 'server');

        $this->assertSame(null, $session);
    }

    public function testDisconnectDoesNotBreak()
    {
        $ssh = new SSHSessionManager($this->logger, $this->filesystem);
        $dummy = $ssh->disconnectAll();

        $this->assertSame(null, $dummy);
    }

    public function getSamplePrivateKeyForTesting()
    {
        return <<<'KEY'
-----BEGIN RSA PRIVATE KEY-----
MIICWwIBAAKBgQC8efE7s4OnEz5bgzno/61dak5wDG3SeKvxtm50hZAIvPLjM69n
PZXlHFR1BbORuV6c7mjwunzztX6Pt/NfQ0wd1CbyKu9189+7vSX/TtHE1nNH9Kjl
P98QMYnnKQ+EFSlucdcGLUhAi7Aiq8G6LYGl50RhKPbnV/5htBHjYbr/gQIDAQAB
AoGAdwJwxofVq5vFFjfIS02WhJPpr2rJtcqol9nf6QelKT9WBwzNxtzmV2MKGVJe
TrfD/Ee2T7sRxzllDw7SR+bQmjeo6sVd+ioH9Cgs+cvar40ioFjEGPAOsL5LBHWb
mY7FrKmcBF0V5qxNhYEM47sxYSjs+fu6jfIkVX+FG85k0nkCQQDhrbLQ6fQjPMWs
PXh99VHfQ82lcdRCsyq4jofwW45AvQWrggmkDxm3Wu7QpbU9lpw1AO8jSmHBd9NH
phm1YG/DAkEA1cypD48a2cqgWS5+UruKtaWmqAW5/ypudIRUyZ89z5QEacgAbTKl
LSqXv4dVcdj4x/0gbu+h54beLYDDbZ8DawJAaf3mifAXVaVpQaftO1tIhI+Xuiho
BJuZaOyoM98MRKOCUjbUyFS/QzpWB3CMWsytuMcjiXOZzf+1H2WHlYQheQJANqIc
1YABIYRY41ExMJ0B/hb9dlQ4Sk8ieJ3UOM17cw7k7c8Q5NabROZsbqH7oKMMN7ak
UhTkL5DUN5Z+2gVXTwJAI+DT7bs6Y4PRFxMrTrVC1/tMfixn6pBfrf1CZyjM2DM/
9HmW1lwGOE2uVePpJzqHgwsRmQgcHFdYDr6GFCE6FA==
-----END RSA PRIVATE KEY-----
KEY;
    }
}
