<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\MemoryLogger;

class DownloaderTest extends PHPUnit_Framework_TestCase
{
    public function testSuccess()
    {
        $logger = new MemoryLogger;
        $api = Mockery::mock('QL\Hal\Agent\Github\ArchiveApi', [
            'download' => true
        ]);

        $action = new Downloader($logger, $api);

        $success = $action('user', 'repo', 'ref', []);
        $this->assertTrue($success);

        $message = $logger[0];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Application code downloaded', $message[1]);
    }

    public function testFailure()
    {
        $logger = new MemoryLogger;
        $api = Mockery::mock('QL\Hal\Agent\Github\ArchiveApi', [
            'download' => false
        ]);

        $action = new Downloader($logger, $api);

        $success = $action('user', 'repo', 'ref', 'php://memory');
        $this->assertFalse($success);

        $message = $logger[0];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('Application code could not be downloaded', $message[1]);

        $this->assertSame('user/repo', $message[2]['repository']);
        $this->assertSame('ref', $message[2]['reference']);
        $this->assertSame('php://memory', $message[2]['downloadTarget']);
    }
}
