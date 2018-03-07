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

class ArtifactVerifierTest extends MockeryTestCase
{
    public $s3;

    public function setUp()
    {
        $this->s3 = Mockery::mock(S3Client::class);
    }

    public function testSuccess()
    {
        $this->s3
            ->expects('waitUntil')
            ->with(
                'ObjectExists',
                [
                    'Bucket' => 'bucket',
                    'Key' => 'key',
                    '@waiter' => [
                        'delay' => 10,
                        'maxAttempts' => 30
                    ]
                ]
            )
            ->andReturns(null)
            ->once();

        $actual = (new ArtifactVerifier)(
            $this->s3,
            'bucket',
            'key'
        );

        $this->assertSame(true, $actual);
    }
}
