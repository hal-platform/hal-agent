<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Job;

use Hal\Agent\Testing\MockeryTestCase;
use Mockery;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class LocalCleanerTest extends MockeryTestCase
{
    public $filesystem;

    public function setUp()
    {
        $this->filesystem = Mockery::mock(Filesystem::class);
    }

    public function testRunCleaner()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->with('/path/to/workspace')
            ->andReturn(true);
        $this->filesystem
            ->shouldReceive('remove')
            ->with('/path/to/workspace')
            ->andReturn(true);

        $this->filesystem
            ->shouldReceive('exists')
            ->with('/path/to/file')
            ->andReturn(false);
        $this->filesystem
            ->shouldReceive('remove')
            ->with('/path/to/file')
            ->never();

        $cleaner = new LocalCleaner($this->filesystem);

        $actual = $cleaner(['/path/to/workspace', '/path/to/file']);
        $this->assertSame(true, $actual);
    }

    public function testAtLeastOneFailureContinuesButReturnsError()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->with('/path/to/workspace')
            ->andReturn(true);
        $this->filesystem
            ->shouldReceive('remove')
            ->with('/path/to/workspace')
            ->andThrow(IOException::class);

        $this->filesystem
            ->shouldReceive('exists')
            ->with('/path/to/file')
            ->andReturn(false);
        $this->filesystem
            ->shouldReceive('remove')
            ->with('/path/to/file')
            ->never();

        $cleaner = new LocalCleaner($this->filesystem);

        $actual = $cleaner(['/path/to/workspace', '/path/to/file']);
        $this->assertSame(false, $actual);
    }
}
