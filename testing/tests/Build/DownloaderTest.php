<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Testing\MemoryLogger;

class DownloaderTest extends PHPUnit_Framework_TestCase
{
    public $file;

    public function setUp()
    {
        $this->file = FIXTURES_DIR . '/downloaded.file';
    }

    public function testSuccess()
    {
        $logger = new MemoryLogger;
        $api = Mockery::mock('QL\Hal\Agent\Github\ArchiveApi', [
            'download' => true
        ]);

        $action = new Downloader($logger, $api);

        $success = $action('user', 'repo', 'ref', $this->file);
        $this->assertTrue($success);

        $message = $logger[0];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Application code downloaded', $message[1]);
        $this->assertSame('0.03 MB', $message[2]['downloadSize']);
    }

    public function testFailure()
    {
        $logger = new MemoryLogger;
        $api = Mockery::mock('QL\Hal\Agent\Github\ArchiveApi', [
            'download' => false
        ]);

        $action = new Downloader($logger, $api);

        $success = $action('user', 'repo', 'ref', $this->file);
        $this->assertFalse($success);

        $message = $logger[0];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('Application code could not be downloaded', $message[1]);

        $this->assertSame('user/repo', $message[2]['repository']);
        $this->assertSame('ref', $message[2]['reference']);
        $this->assertSame($this->file, $message[2]['downloadTarget']);

    }
}
