<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Aws\S3\S3Client;
use Hal\Agent\Deploy\S3\Sync\Sync;
use Hal\Agent\Deploy\S3\Sync\SyncManager;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class SyncUploaderTest extends MockeryTestCase
{
    public $s3;

    public function setUp()
    {
        $this->s3 = Mockery::mock(S3Client::class);
        $this->synchronizer = Mockery::mock(SyncManager::class);
    }

    public function testSuccess()
    {
        $this->synchronizer
            ->expects('sync')
            ->with($this->s3, 'temp_archive', 'bucket', 'directory', Sync::COMPARE | Sync::REMOVE)
            ->andReturns(null)
            ->once();

        $syncUploader = new SyncUploader($this->synchronizer);

        $actual = $syncUploader(
            $this->s3,
            'temp_archive',
            'bucket',
            'directory',
            [ 'meta' => 'data' ]
        );

        $this->assertSame(true, $actual);
    }
}
