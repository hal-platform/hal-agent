<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Aws\S3\S3Client;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use StdClass;

class ArtifactUploaderTest extends MockeryTestCase
{
    public $s3;
    public $body;
    public $fileStreamer;

    public function setUp()
    {
        $this->s3 = Mockery::mock(S3Client::class);
        $this->body = "Body";
        $this->fileStreamer = Mockery::mock(StdClass::class . '[__invoke]');
    }

    public function testSuccess()
    {
        $this->fileStreamer
            ->expects('__invoke')
            ->with('temp_archive', 'r+')
            ->andReturns($this->body)
            ->once();

        $this->s3
            ->expects('upload')
            ->with(
                'bucket',
                'file',
                $this->body,
                'bucket-owner-full-control',
                [ 'params' => [ 'Metadata' => [ 'meta' => 'data' ] ] ]
            )
            ->andReturns($this->body)
            ->once();

        $artifactUploader = new ArtifactUploader([$this->fileStreamer, '__invoke']);

        $actual = $artifactUploader(
            $this->s3,
            'temp_archive',
            'bucket',
            'file',
            [ 'meta' => 'data' ]
        );

        $this->assertSame(true, $actual);
    }
}
