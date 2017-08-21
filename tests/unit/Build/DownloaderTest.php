<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Github\ArchiveApi;
use Hal\Agent\Github\GitHubException;
use Hal\Agent\Logger\EventLogger;

class DownloaderTest extends MockeryTestCase
{
    public $file;
    public $logger;

    public function setUp()
    {
        $this->file = FIXTURES_DIR . '/downloaded.file';
        $this->logger = Mockery::mock(EventLogger::class);
    }

    public function testSuccess()
    {
        $api = Mockery::mock(ArchiveApi::class, [
            'download' => true
        ]);

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), [
                'size' => '0.03 MB'
            ])->once();

        $action = new Downloader($this->logger, $api);

        $success = $action('user', 'repo', 'ref', $this->file);
        $this->assertTrue($success);
    }

    public function testFailure()
    {
        $api = Mockery::mock(ArchiveApi::class, [
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

    public function testApiThrowsException()
    {
        $api = Mockery::mock(ArchiveApi::class);
        $api
            ->shouldReceive('download')
            ->andThrow(GitHubException::class);

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
