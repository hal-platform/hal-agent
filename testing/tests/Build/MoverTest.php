<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;

class MoverTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $filesystem;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');
    }

    public function testExceptionThrownBlowsUp()
    {
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

}
