<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Remoting;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;

class CredentialWalletTest extends MockeryTestCase
{
    public function testServerSpecificCredentialsPreferredOverWildcard()
    {
        $wallet = new CredentialWallet;
        $wallet->importCredential('username', '*', 'key:key/path/1');
        $wallet->importCredential('username', 'server', 'key:key/path/2'); // specific server pair will be picked over wildcard
        $wallet->importCredential('username', 'server', 'key:key/path/3'); // erroneous duplicate

        $cred = $wallet->findCredential('username', 'server');

        $this->assertSame('key/path/2', $cred->keyPath());
    }

    public function testFirstCredentialIsPreferredIfDuplicatesSet()
    {
        $wallet = new CredentialWallet;
        $wallet->importCredential('username', 'server', 'key:key/path/1');
        $wallet->importCredential('username', 'server', 'key:key/path/2');
        $cred = $wallet->findCredential('username', 'server');

        $this->assertSame('key/path/1', $cred->keyPath());
    }

}
