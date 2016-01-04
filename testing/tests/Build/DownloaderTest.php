<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;

class DownloaderTest extends PHPUnit_Framework_TestCase
{
    public $file;
    public $logger;

    public function setUp()
    {
        $this->file = FIXTURES_DIR . '/downloaded.file';
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
    }

    public function testSuccess()
    {
        $api = Mockery::mock('QL\Hal\Agent\Github\ArchiveApi', [
            'download' => true
        ]);

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), [
                'size' => '0.03 MB'
            ])->once();
        $this->logger
            ->shouldReceive('keep')
            ->with('filesize', ['download' => '29160'])
            ->once();

        $action = new Downloader($this->logger, $api);

        $success = $action('user', 'repo', 'ref', $this->file);
        $this->assertTrue($success);
    }

    public function testFailure()
    {
        $api = Mockery::mock('QL\Hal\Agent\Github\ArchiveApi', [
            'download' => false
        ]);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'repository' => 'user/repo',
                'reference' => 'ref',
                'target' => $this->file
            ])->once();

        $action = new Downloader($this->logger, $api);

        $success = $action('user', 'repo', 'ref', $this->file);
        $this->assertFalse($success);
    }
}
