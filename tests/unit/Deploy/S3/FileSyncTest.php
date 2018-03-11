<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3;

use Aws\Result;
use Aws\ResultPaginator;
use Aws\S3\S3Client;
use Hal\Agent\AWS\S3Batcher;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class FileSyncTest extends MockeryTestCase
{
    public $s3;
    public $finder;
    public $comparator;
    public $batcher;

    public $file;
    public $paginator;
    public $result;
    public $transfer;
    public $delete;
    public $promise;
    public $transferManager;
    public $batchDeleteManager;

    public function setUp()
    {
        $this->s3 = Mockery::mock(S3Client::class);
        $this->finder = Mockery::mock(Finder::class);
        $this->comparator = Mockery::mock(Comparator::class);
        $this->batcher = Mockery::mock(S3Batcher::class);

        $this->file = Mockery::mock(SplFileInfo::class);
        $this->paginator = Mockery::mock(ResultPaginator::class);
        $this->result = Mockery::mock(Result::class);
    }

    public function testSuccess()
    {
        $expectedUploadFiles = [
            'file3.jpg' => '/path/to/file3.jpg',
            'dir/file4.zip' => '/path/to/dir/file4.zip'
        ];

        $expectedDeleteFiles = [
            'file5.log' => ['Key' => 'file5.log'],
        ];

        $this->finder
            ->shouldReceive('create')
            ->andReturn($this->finder);

        $this->finder
            ->shouldReceive('files->in')
            ->with('/local_path')
            ->once()
            ->andReturn([
                $this->generateFileMock('file1.doc'),
                $this->generateFileMock('file2.txt'),
                $this->generateFileMock('file3.jpg'),
                $this->generateFileMock('dir/file4.zip'),
            ]);

        $this->s3
            ->shouldReceive('getPaginator')
            ->with(
                'ListObjects',
                [
                    'Bucket' => 'remote_bucket',
                    'Prefix' => 'remote_path/path'
                ]
            )
            ->andReturn([
                new Result([
                    'Contents' => [
                        $this->generateS3ObjectMock('file1.doc'),
                        $this->generateS3ObjectMock('file2.txt'),
                        $this->generateS3ObjectMock('file5.log'),
                    ]
                ])
            ]);

        $this->comparator
            ->shouldReceive('areSame')
            ->andReturn(true, true, false);

        $this->batcher
            ->shouldReceive('transferFiles')
            ->with($this->s3, $expectedUploadFiles, '/local_path', 'remote_bucket', 'remote_path/path')
            ->once();

        $this->batcher
            ->shouldReceive('deleteFiles')
            ->with($this->s3, $expectedDeleteFiles, 'remote_bucket')
            ->once();

        $sync = new FileSync($this->finder, $this->comparator, $this->batcher);
        $sync->withFlag('COMPARE_FILES');
        $sync->withFlag('REMOVE_EXTRA_FILES');

        $sync->filesync($this->s3, '/local_path', 'remote_bucket', 'remote_path/path');
    }

    private function generateFileMock($filename)
    {
        $mock = Mockery::mock(SplFileInfo::class, [
            'getRelativePathname' => $filename,
            'getPathname' => '/path/to/' . $filename,
        ]);

        return $mock;
    }

    private function generateS3ObjectMock($filename)
    {
        return [
            'Key' => $filename,
        ];
    }
}
