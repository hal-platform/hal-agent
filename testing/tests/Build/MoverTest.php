<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;

class MoverTest extends MockeryTestCase
{
    public $logger;
    public $filesystem;

    public function setUp()
    {
        $this->logger = Mockery::mock('Hal\Agent\Logger\EventLogger');
        $this->filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');
    }

    public function testSourceNotFoundBlowsUp()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->with('from')
            ->andReturn(false);
        $this->filesystem
            ->shouldReceive('copy')
            ->never();
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $mover = new Mover($this->logger, $this->filesystem);

        $result = $mover('from', 'to');
        $this->assertSame(false, $result);
    }

    public function testExceptionThrownBlowsUp()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->with('from')
            ->andReturn(true);
        $this->filesystem
            ->shouldReceive('copy')
            ->with('from', 'to', true)
            ->andThrow('Symfony\Component\Filesystem\Exception\IOException');
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $mover = new Mover($this->logger, $this->filesystem);

        $result = $mover('from', 'to');
        $this->assertSame(false, $result);
    }

    public function testFileIsCopied()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->with('from')
            ->andReturn(true);
        $this->filesystem
            ->shouldReceive('copy')
            ->with('from', 'to', true)
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any())
            ->once();

        $mover = new Mover($this->logger, $this->filesystem);

        $result = $mover('from', 'to');
        $this->assertSame(true, $result);
    }

    public function testMultipleSourcesWorks()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->with('from1')
            ->andReturn(false)
            ->once();
        $this->filesystem
            ->shouldReceive('exists')
            ->with('from2')
            ->andReturn(true)
            ->once();

        $this->filesystem
            ->shouldReceive('copy')
            ->with('from2', 'to', true)
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any())
            ->once();

        $mover = new Mover($this->logger, $this->filesystem);

        $sources = ['from1', 'from2', 'from3'];
        $result = $mover($sources, 'to');
        $this->assertSame(true, $result);
    }
}
