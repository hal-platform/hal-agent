<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Remoting;

use Mockery;
use PHPUnit_Framework_TestCase;

class CredentialWalletTest extends PHPUnit_Framework_TestCase
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