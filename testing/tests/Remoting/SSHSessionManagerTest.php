<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Remoting;

use Mockery;
use PHPUnit_Framework_TestCase;

class SSHSessionManagerTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $credentials;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->credentials = Mockery::mock('QL\Hal\Agent\Remoting\CredentialWallet');
    }

    public function testNoCredentialsLogsError()
    {
        $expectedContext = [
            'user' => 'username',
            'server' => 'server'
        ];

        $this->credentials
            ->shouldReceive('findCredential')
            ->andReturnNull();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', SSHSessionManager::ERR_NO_CREDENTIALS, $expectedContext)
            ->once();

        $ssh = new SSHSessionManager($this->logger, $this->credentials);
        $session = $ssh->createSession('username', 'server');

        $this->assertSame(null, $session);
    }

    public function testPrivateKeyMissingLogsError()
    {
        $expectedContext = [
            'user' => 'username',
            'server' => 'server'
        ];

        $cred = Mockery::mock('QL\Hal\Agent\Remoting\Credential', [
            'isKeyCredential' => true,
            'privateKey' => null
        ]);

        $this->credentials
            ->shouldReceive('findCredential')
            ->andReturn($cred);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', SSHSessionManager::ERR_MISSING_PRIVATE_KEY, $expectedContext)
            ->once();

        $ssh = new SSHSessionManager($this->logger, $this->credentials);
        $session = $ssh->createSession('username', 'server');

        $this->assertSame(null, $session);
    }

    public function testPrivateKeyInvalidLogsError()
    {
        $expectedContext = [
            'user' => 'username',
            'server' => 'server'
        ];

        $cred = Mockery::mock('QL\Hal\Agent\Remoting\Credential', [
            'isKeyCredential' => true,
            'privateKey' => 'derp'
        ]);

        $this->credentials
            ->shouldReceive('findCredential')
            ->andReturn($cred);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', SSHSessionManager::ERR_MISSING_PRIVATE_KEY, $expectedContext)
            ->once();

        $ssh = new SSHSessionManager($this->logger, $this->credentials);
        $session = $ssh->createSession('username', 'server');

        $this->assertSame(null, $session);
    }

    public function testParsingPortFromServerName()
    {
        $expectedContext = [
            'user' => 'username123456789',
            'server' => 'localhost:3300'
        ];

        $cred = Mockery::mock('QL\Hal\Agent\Remoting\Credential', [
            'isKeyCredential' => true,
            'privateKey' => $this->getSamplePrivateKeyForTesting()
        ]);

        $this->credentials
            ->shouldReceive('findCredential')
            ->with('username123456789', 'localhost')
            ->andReturn($cred);

        $context = null;
        $this->logger
            ->shouldReceive('event')
            ->with('failure', SSHSessionManager::ERR_CONNECT_SERVER, Mockery::on(function($v) use (&$context) {
                $context = $v;
                return true;
            }));

        $ssh = new SSHSessionManager($this->logger, $this->credentials);
        $session = $ssh->createSession('username123456789', 'localhost:3300');

        $this->assertSame(null, $session);
        $this->assertSame($expectedContext['server'], $context['server']);
    }

    public function testLoginFailureForUnknownServer()
    {
        $expectedContext = [
            'user' => 'username123456789',
            'server' => 'server123456789',
            'errors' => [
                'SSH Warning: fsockopen(): php_network_getaddresses: getaddrinfo failed: nodename nor servname provided, or not known',
                'SSH Warning: fsockopen(): unable to connect to server123456789:22 (php_network_getaddresses: getaddrinfo failed: nodename nor servname provided, or not known)',
                'SSH User Notice: Cannot connect to server123456789:22. Error 0. php_network_getaddresses: getaddrinfo failed: nodename nor servname provided, or not known'
            ]
        ];

        $cred = Mockery::mock('QL\Hal\Agent\Remoting\Credential', [
            'isKeyCredential' => true,
            'privateKey' => $this->getSamplePrivateKeyForTesting()
        ]);

        $this->credentials
            ->shouldReceive('findCredential')
            ->with('username123456789', 'server123456789')
            ->andReturn($cred);

        $context = null;
        $this->logger
            ->shouldReceive('event')
            ->with('failure', SSHSessionManager::ERR_CONNECT_SERVER, Mockery::on(function($v) use (&$context) {
                $context = $v;
                return true;
            }));

        $ssh = new SSHSessionManager($this->logger, $this->credentials);
        $session = $ssh->createSession('username123456789', 'server123456789');

        $this->assertSame(null, $session);
        $this->assertSame($expectedContext, $context);
    }

    public function testDisconnectDoesNotBreak()
    {
        $ssh = new SSHSessionManager($this->logger, $this->credentials);
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
