<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3\Sync;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Aws\S3\S3Client;
use Aws\S3\Transfer;
use Aws\S3\BatchDelete;
use Aws\ResultPaginator;
use Aws\Result;
use GuzzleHttp\Promise\PromiseInterface;
use Symfony\Component\Finder\Finder;
use ArrayIterator;
use Symfony\Component\Finder\SplFileInfo;
use DateTime;
use DateTimeZone;
use Exception;

//@TODO: Add tests for promise functionality
class SyncTest extends MockeryTestCase
{
    public $s3;
    public $finder;
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
        $this->file = Mockery::mock(SplFileInfo::class);
        $this->paginator = Mockery::mock(ResultPaginator::class);
        $this->result = Mockery::mock(Result::class);
        $this->transfer = Mockery::mock(Transfer::class);
        $this->delete = Mockery::mock(BatchDelete::class);
        $this->promise = Mockery::mock(PromiseInterface::class);
        $this->transferManager = Mockery::mock(TransferManager::class);
        $this->batchDeleteManager = Mockery::mock(BatchDeleteManager::class);
    }

    public function testNoOptions()
    {
        $source = 'testsource';
        $bucket = 'testdestination';
        $object = '';
        $flags = null;

        $f1 = $this->generateFileMock('doesntexistons3.doc');
        $f2 = $this->generateFileMock('alldifferent.txt', 4201, 123);
        $f3 = $this->generateFileMock('samesize.jpg', 11235, 12345);
        $f4 = $this->generateFileMock('samedate.zip', 4201, 0);
        $f5 = $this->generateFileMock('allsame.js', 1337, 2114602417);

        $o1 = $this->generateS3ObjectMock('alldifferent.txt', 1024, 'last year');
        $o2 = $this->generateS3ObjectMock('samesize.jpg', 11235, 'now');
        $o3 = $this->generateS3ObjectMock('samedate.zip', 998877, '1970-01-01 00:00:00');
        $o4 = $this->generateS3ObjectMock('allsame.js', 1337, '2037-01-03 13:33:37');
        $o5 = $this->generateS3ObjectMock('doesntexistlocally');

        $this->finder
            ->shouldReceive('files')
            ->andReturnSelf();
        $this->finder
            ->shouldReceive('in')
            ->with('testsource')
            ->andReturnSelf();
        $this->finder
            ->shouldReceive('getIterator')
            ->andReturn(new ArrayIterator([ $f1, $f2, $f3, $f4, $f5 ]));

        $this->s3
            ->shouldReceive('getPaginator')
            ->with('ListObjects', [ 'Bucket' => 'testdestination' ])
            ->andReturn([
                new Result([
                    'Contents' => [ $o1, $o2, $o3, $o4, $o5 ]
                ])
            ]);

        $sync = new Sync(
            $this->transferManager,
            $this->batchDeleteManager,
            $this->finder,
            $this->s3,
            $source,
            $bucket,
            $object,
            $flags
        );

        $arguments = [];
        $this->transferManager
            ->shouldReceive('build')
            ->with(
                Mockery::on(function ($v) use (&$arguments) { $arguments['s3Client'] = $v; return true; }),
                Mockery::on(function ($v) use (&$arguments) { $arguments['filesToUpload'] = $v; return true; }),
                Mockery::on(function ($v) use (&$arguments) { $arguments['destination'] = $v; return true; }),
                Mockery::on(function ($v) use (&$arguments) { $arguments['options'] = $v; return true; })
            )
            ->andReturnSelf();

        // Nothing should be deleted from S3
        $this->batchDeleteManager
            ->shouldNotReceive('fromIterator');
        $this->batchDeleteManager
            ->shouldNotReceive('fromListObjects');

        $this->transferManager
            ->shouldReceive('promise')
            ->andReturn($this->promise);

        $this->promise
            ->shouldReceive('wait')
            ->andReturn(null);

        $sync->sync();

        $expectedOptions = [
            'base_dir' => 'testsource',
            'concurrency' => 20
        ];
        $expectedFiles = [
            'doesntexistons3.doc' => '/path/to/doesntexistons3.doc',
            'alldifferent.txt' => '/path/to/alldifferent.txt',
            'samesize.jpg' => '/path/to/samesize.jpg',
            'samedate.zip' => '/path/to/samedate.zip',
            'allsame.js' => '/path/to/allsame.js'
        ];

        $this->assertSame($this->s3, $arguments['s3Client']);

        // All files should be uploaded
        $capturedFiles = [];
        foreach ($arguments['filesToUpload'] as $k => $v) { $capturedFiles[$k] = $v; }
        $this->assertSame($expectedFiles, $capturedFiles);

        $this->assertSame('s3://testdestination', $arguments['destination']);
        $this->assertSame($expectedOptions, $arguments['options']);
    }

    public function testCompare()
    {
        $source = 'testsource';
        $bucket = 'testdestination';
        $object = '';
        $flags = Sync::COMPARE;

        $f1 = $this->generateFileMock('doesntexistons3.doc');
        $f2 = $this->generateFileMock('alldifferent.txt', 4201, 123);
        $f3 = $this->generateFileMock('samesize.jpg', 11235, 12345);
        $f4 = $this->generateFileMock('samedate.zip', 4201, 0);
        $f5 = $this->generateFileMock('allsame.js', 1337, 2114602417);

        $o1 = $this->generateS3ObjectMock('alldifferent.txt', 1024, 'last year');
        $o2 = $this->generateS3ObjectMock('samesize.jpg', 11235, 'now');
        $o3 = $this->generateS3ObjectMock('samedate.zip', 998877, '1970-01-01 00:00:00');
        $o4 = $this->generateS3ObjectMock('allsame.js', 1337, '2037-01-03 13:33:37');
        $o5 = $this->generateS3ObjectMock('doesntexistlocally');

        $this->finder
            ->shouldReceive('files')
            ->andReturnSelf();
        $this->finder
            ->shouldReceive('in')
            ->with('testsource')
            ->andReturnSelf();
        $this->finder
            ->shouldReceive('getIterator')
            ->andReturn(new ArrayIterator([ $f1, $f2, $f3, $f4, $f5 ]));

        $this->s3
            ->shouldReceive('getPaginator')
            ->with('ListObjects', [ 'Bucket' => 'testdestination' ])
            ->andReturn([
                new Result([
                    'Contents' => [ $o1, $o2, $o3, $o4, $o5 ]
                ])
            ]);

        $sync = new Sync(
            $this->transferManager,
            $this->batchDeleteManager,
            $this->finder,
            $this->s3,
            $source,
            $bucket,
            $object,
            $flags
        );

        $arguments = [];
        $this->transferManager
            ->shouldReceive('build')
            ->with(
                Mockery::any(),
                Mockery::on(function ($v) use (&$arguments) { $arguments['filesToUpload'] = $v; return true; }),
                Mockery::any(),
                Mockery::any()
            )
            ->andReturnSelf();

        // Nothing should be deleted from S3
        $this->batchDeleteManager
            ->shouldNotReceive('fromIterator');
        $this->batchDeleteManager
            ->shouldNotReceive('fromListObjects');

        $this->transferManager
            ->shouldReceive('promise')
            ->andReturn($this->promise);

        $this->promise
            ->shouldReceive('wait')
            ->andReturn(null);

        $sync->sync();

        $expectedFiles = [
            'doesntexistons3.doc' => '/path/to/doesntexistons3.doc',
            'alldifferent.txt' => '/path/to/alldifferent.txt',
            'samesize.jpg' => '/path/to/samesize.jpg',
            'samedate.zip' => '/path/to/samedate.zip',
        ];

        // The files that didn't match s3 objects should be uploaded
        $capturedFiles = [];
        foreach ($arguments['filesToUpload'] as $k => $v) { $capturedFiles[$k] = $v; }
        $this->assertSame($expectedFiles, $capturedFiles);
    }

    public function testRemove()
    {
        $source = 'testsource';
        $bucket = 'testdestination';
        $object = '';
        $flags = Sync::REMOVE;

        $f1 = $this->generateFileMock('doesntexistons3.doc');
        $f2 = $this->generateFileMock('alldifferent.txt', 4201, 123);
        $f3 = $this->generateFileMock('samesize.jpg', 11235, 12345);
        $f4 = $this->generateFileMock('samedate.zip', 4201, 0);
        $f5 = $this->generateFileMock('allsame.js', 1337, 2114602417);

        $o1 = $this->generateS3ObjectMock('alldifferent.txt', 1024, 'last year');
        $o2 = $this->generateS3ObjectMock('samesize.jpg', 11235, 'now');
        $o3 = $this->generateS3ObjectMock('samedate.zip', 998877, '1970-01-01 00:00:00');
        $o4 = $this->generateS3ObjectMock('allsame.js', 1337, '2037-01-03 13:33:37');
        $o5 = $this->generateS3ObjectMock('doesntexistlocally');

        $this->finder
            ->shouldReceive('files')
            ->andReturnSelf();
        $this->finder
            ->shouldReceive('in')
            ->with('testsource')
            ->andReturnSelf();
        $this->finder
            ->shouldReceive('getIterator')
            ->andReturn(new ArrayIterator([ $f1, $f2, $f3, $f4, $f5 ]));

        $this->s3
            ->shouldReceive('getPaginator')
            ->with('ListObjects', [ 'Bucket' => 'testdestination' ])
            ->andReturn([
                new Result([
                    'Contents' => [ $o1, $o2, $o3, $o4, $o5 ]
                ])
            ]);

        $sync = new Sync(
            $this->transferManager,
            $this->batchDeleteManager,
            $this->finder,
            $this->s3,
            $source,
            $bucket,
            $object,
            $flags
        );

        $transferArguments = [];
        $this->transferManager
            ->shouldReceive('build')
            ->with(
                Mockery::any(),
                Mockery::on(function ($v) use (&$transferArguments) { $transferArguments['filesToUpload'] = $v; return true; }),
                Mockery::any(),
                Mockery::any()
            )
            ->andReturnSelf();
        $this->transferManager
            ->shouldReceive('promise')
            ->andReturn($this->promise);

        $deleteArguments = [];
        $this->batchDeleteManager
            ->shouldReceive('fromIterator')
            ->with(
                Mockery::on(function ($v) use (&$deleteArguments) { $deleteArguments['s3Client'] = $v; return true; }),
                Mockery::on(function ($v) use (&$deleteArguments) { $deleteArguments['destination'] = $v; return true; }),
                Mockery::on(function ($v) use (&$deleteArguments) { $deleteArguments['filesToDelete'] = $v; return true; })
            )
            ->andReturnSelf();
        $this->batchDeleteManager
            ->shouldReceive('promise')
            ->with()
            ->andReturnSelf();

        $closure = null;
        $this->promise
            ->shouldReceive('then')
            ->with(Mockery::on(function ($provided) use (&$closure) { $closure = $provided; return true; }))
            ->andReturnSelf();

        $this->promise
            ->shouldReceive('wait')
            ->andReturn();

        $sync->sync();

        $expectedFiles = [
            'doesntexistons3.doc' => '/path/to/doesntexistons3.doc',
            'alldifferent.txt' => '/path/to/alldifferent.txt',
            'samesize.jpg' => '/path/to/samesize.jpg',
            'samedate.zip' => '/path/to/samedate.zip',
            'allsame.js' => '/path/to/allsame.js'
        ];
        $expectedDeleteFiles = [
            'doesntexistlocally' => $o5
        ];

        // Needs to be called BEFORE asserting batchDelete arguments. Promise magic.
        $this->assertSame($this->batchDeleteManager, $closure());

        $this->assertSame($this->s3, $deleteArguments['s3Client']);
        $this->assertSame('testdestination', $deleteArguments['destination']);

        // All files should be uploaded
        $capturedFiles = [];
        foreach ($transferArguments['filesToUpload'] as $k => $v) { $capturedFiles[$k] = $v; }
        $this->assertSame($expectedFiles, $capturedFiles);

        // The files that don't exist locally should be deleted
        $capturedFiles = [];
        foreach ($deleteArguments['filesToDelete'] as $k => $v) { $capturedFiles[$k] = $v; }
        $this->assertSame($expectedDeleteFiles, $capturedFiles);
    }

    public function testCompareAndRemoveAndPagedS3Results()
    {
        $source = 'testsource';
        $bucket = 'testdestination';
        $object = '';
        $flags = Sync::COMPARE | Sync::REMOVE;

        $f1 = $this->generateFileMock('doesntexistons3.doc');
        $f2 = $this->generateFileMock('alldifferent.txt', 4201, 123);
        $f3 = $this->generateFileMock('samesize.jpg', 11235, 12345);
        $f4 = $this->generateFileMock('samedate.zip', 4201, 0);
        $f5 = $this->generateFileMock('allsame.js', 1337, 2114602417);

        $o1 = $this->generateS3ObjectMock('alldifferent.txt', 1024, 'last year');
        $o2 = $this->generateS3ObjectMock('samesize.jpg', 11235, 'now');
        $o3 = $this->generateS3ObjectMock('samedate.zip', 998877, '1970-01-01 00:00:00');
        $o4 = $this->generateS3ObjectMock('allsame.js', 1337, '2037-01-03 13:33:37');
        $o5 = $this->generateS3ObjectMock('doesntexistlocally');

        $this->finder
            ->shouldReceive('files')
            ->andReturnSelf();
        $this->finder
            ->shouldReceive('in')
            ->with('testsource')
            ->andReturnSelf();
        $this->finder
            ->shouldReceive('getIterator')
            ->andReturn(new ArrayIterator([ $f1, $f2, $f3, $f4, $f5 ]));

        $this->s3
            ->shouldReceive('getPaginator')
            ->with('ListObjects', [ 'Bucket' => 'testdestination' ])
            ->andReturn([
                new Result([
                    'Contents' => [ $o1, $o2, $o3 ]
                ]),
                new Result([
                    'Contents' => [ $o4, $o5 ]
                ])
            ]);

        $sync = new Sync(
            $this->transferManager,
            $this->batchDeleteManager,
            $this->finder,
            $this->s3,
            $source,
            $bucket,
            $object,
            $flags
        );

        $additions = null;
        $this->transferManager
            ->shouldReceive('build')
            ->with(
                Mockery::any(),
                Mockery::on(function ($v) use (&$additions) { $additions = $v; return true; }),
                Mockery::any(),
                Mockery::any()
            )
            ->andReturnSelf();
        $this->transferManager
            ->shouldReceive('promise')
            ->andReturn($this->promise);

        $deletions = null;
        $this->batchDeleteManager
            ->shouldReceive('fromIterator')
            ->with(
                Mockery::any(),
                Mockery::any(),
                Mockery::on(function ($v) use (&$deletions) { $deletions = $v; return true; })
            )
            ->andReturnSelf();
        $this->batchDeleteManager
            ->shouldReceive('promise')
            ->with()
            ->andReturnSelf();

        $this->promise
            ->shouldReceive('then')
            ->with(Mockery::on(function ($provided) { $provided(); return true; }))
            ->andReturnSelf();
        $this->promise
            ->shouldReceive('wait')
            ->andReturn(null);

        $sync->sync();

        $expectedFiles = [
            'doesntexistons3.doc' => '/path/to/doesntexistons3.doc',
            'alldifferent.txt' => '/path/to/alldifferent.txt',
            'samesize.jpg' => '/path/to/samesize.jpg',
            'samedate.zip' => '/path/to/samedate.zip',
        ];
        $expectedDeleteFiles = [
            'doesntexistlocally' => $o5
        ];

        // All files should be uploaded
        $capturedFiles = [];
        foreach ($additions as $k => $v) { $capturedFiles[$k] = $v; }
        $this->assertSame($expectedFiles, $capturedFiles);

        // The files that don't exist locally should be deleted
        $capturedFiles = [];
        foreach ($deletions as $k => $v) { $capturedFiles[$k] = $v; }
        $this->assertSame($expectedDeleteFiles, $capturedFiles);
    }

    public function testWithNoS3Objects()
    {
        $source = 'testsource';
        $bucket = 'testdestination';
        $object = '';
        $flags = Sync::COMPARE | Sync::REMOVE;

        $f1 = $this->generateFileMock('doesntexistons3.doc');
        $f2 = $this->generateFileMock('alldifferent.txt', 4201, 123);
        $f3 = $this->generateFileMock('samesize.jpg', 11235, 12345);
        $f4 = $this->generateFileMock('samedate.zip', 4201, 0);
        $f5 = $this->generateFileMock('allsame.js', 1337, 2114602417);

        $this->finder
            ->shouldReceive('files')
            ->andReturnSelf();
        $this->finder
            ->shouldReceive('in')
            ->with('testsource')
            ->andReturnSelf();
        $this->finder
            ->shouldReceive('getIterator')
            ->andReturn(new ArrayIterator([ $f1, $f2, $f3, $f4, $f5 ]));

        $this->s3
            ->shouldReceive('getPaginator')
            ->with('ListObjects', [ 'Bucket' => 'testdestination' ])
            ->andReturn([]);

        $sync = new Sync(
            $this->transferManager,
            $this->batchDeleteManager,
            $this->finder,
            $this->s3,
            $source,
            $bucket,
            $object,
            $flags
        );

        $additions = null;
        $this->transferManager
            ->shouldReceive('build')
            ->with(
                Mockery::any(),
                Mockery::on(function ($v) use (&$additions) { $additions = $v; return true; }),
                Mockery::any(),
                Mockery::any()
            )
            ->andReturnSelf();
        $this->transferManager
            ->shouldReceive('promise')
            ->andReturn($this->promise);

        $deletions = [];
        $this->batchDeleteManager
            ->shouldReceive('fromIterator')
            ->with(
                Mockery::any(),
                Mockery::any(),
                Mockery::on(function ($v) use (&$deletions) { $deletions = $v; return true; })
            )
            ->andReturnSelf();
        $this->batchDeleteManager
            ->shouldReceive('promise')
            ->with()
            ->andReturnSelf();

        $this->promise
            ->shouldReceive('then')
            ->with(Mockery::on(function ($provided) { $provided(); return true; }))
            ->andReturnSelf();
        $this->promise
            ->shouldReceive('wait')
            ->andReturn(null);

        $sync->sync();

        $expectedFiles = [
            'doesntexistons3.doc' => '/path/to/doesntexistons3.doc',
            'alldifferent.txt' => '/path/to/alldifferent.txt',
            'samesize.jpg' => '/path/to/samesize.jpg',
            'samedate.zip' => '/path/to/samedate.zip',
            'allsame.js' => '/path/to/allsame.js'
        ];
        $expectedDeleteFiles = [];

        // All files should be uploaded
        $capturedFiles = [];
        foreach ($additions as $k => $v) { $capturedFiles[$k] = $v; }
        $this->assertSame($expectedFiles, $capturedFiles);

        // The files that don't exist locally should be deleted
        $capturedFiles = [];
        foreach ($deletions as $k => $v) { $capturedFiles[$k] = $v; }
        $this->assertSame($expectedDeleteFiles, $capturedFiles);
    }

    /**
     * Helper method to generate a SplFileInfo mock object
     *
     * @param string $filename
     * @param int $size
     * @param int $modifiedTime
     *
     * @return SplFileInfo $mock
     */
    private function generateFileMock($filename, $size = 0, $modifiedTime = 0)
    {
        $mock = Mockery::mock(SplFileInfo::class, [
            'getRelativePathname' => $filename,
            'getPathname' => '/path/to/' . $filename,
            'getSize' => $size,
            'getMTime' => $modifiedTime
        ]);

        return $mock;
    }

    /**
     * Helper method to generate an S3Object result array
     *
     * @param string $filename
     * @param int    $size
     * @param string $modifiedTime
     *
     * @return array $mock
     */
    private function generateS3ObjectMock($filename, $size = 0, $modified = '')
    {
        return [
            'Key' => $filename,
            'Size' => $size,
            'LastModified' => new DateTime($modified, new DateTimeZone('UTC'))
        ];
    }
}
