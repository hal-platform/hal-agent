<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Aws\S3\S3Client;
use Hal\Agent\Deploy\S3\FileSync;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class SyncUploaderTest extends MockeryTestCase
{
    public $s3;
    public $filesync;

    public function setUp()
    {
        $this->s3 = Mockery::mock(S3Client::class);
        $this->filesync = Mockery::mock(FileSync::class);
    }

    public function testSuccess()
    {
        $this->filesync
            ->shouldReceive('filesync')
            ->with($this->s3, './local_path', 'bucket', 'object/path')
            ->once();

        $uploader = new SyncUploader($this->filesync);

        $actual = $uploader(
            $this->s3,
            './local_path',
            'bucket',
            'object/path'
        );

        $this->assertSame(true, $actual);
    }

    public function testFailIfSourceTraverses()
    {
        $uploader = new SyncUploader($this->filesync);

        $actual = $uploader(
            $this->s3,
            '/dir/../local_path',
            'bucket',
            'object/path'
        );

        $this->assertSame(false, $actual);
    }

    /**
     * @dataProvider pathSanitizationsProvider
     */
    public function testSanitizeObjectPaths($input, $expected)
    {
        $this->filesync
            ->shouldReceive('filesync')
            ->with($this->s3, './local_path', 'bucket', $expected)
            ->once();

        $uploader = new SyncUploader($this->filesync);

        $actual = $uploader(
            $this->s3,
            './local_path',
            'bucket',
            $input
        );

        $this->assertSame(true, $actual);
    }

    public function pathSanitizationsProvider()
    {
        return [
            ['.',               ''],
            ['./path',          'path'],
            ['/object/path/',   'object/path'],
            ['/object/path',    'object/path'],
            ['./',              '']
        ];
    }

}
