<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\CodeDeploy\Steps;

use Hal\Agent\Deploy\S3\Steps\Compressor as S3Compressor;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;

class CompressorTest extends MockeryTestCase
{
    public $s3Compressor;

    public function setUp()
    {
        $this->s3Compressor = Mockery::mock(S3Compressor::class);
    }

    public function testSuccess()
    {
        $this->s3Compressor
            ->shouldReceive('__invoke')
            ->with('source', 'target', 'destination')
            ->andReturn(true);

        $compressor = new Compressor($this->s3Compressor);
        $actual = $compressor('source', 'target', 'destination');

        $this->assertSame(true, $actual);
    }

    public function testFailure()
    {
        $this->s3Compressor
            ->shouldReceive('__invoke')
            ->with('source', 'target', 'destination')
            ->andReturn(false);

        $compressor = new Compressor($this->s3Compressor);
        $actual = $compressor('source', 'target', 'destination');

        $this->assertSame(false, $actual);
    }
}
