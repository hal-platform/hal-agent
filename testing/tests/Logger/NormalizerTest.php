<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use PHPUnit_Framework_TestCase;

class NormalizerTest extends PHPUnit_Framework_TestCase
{
    public function testFlattenedOutput()
    {
        $data = [
            'key1' => 'string',
            'key2' => false,
            'key3' => null,
            'key4' => [
                'nested',
                'array'
            ],
            'key5' => [
                'nested' => [
                    'we' => 'must',
                    'go' => 'deeper'
                ],
                'array' => 'arraydata2'
            ],
            'key6' => 'test test test
test2 test2 test2
test3 test3 test3',
            'key7' => ''
        ];

        $expected = <<<OUTPUT
key1:
string

key2:
false

key3:
NULL

key4:
    0:
    nested
    1:
    array

key5:
    nested:
        we:
        must
        go:
        deeper
    array:
    arraydata2

key6:
test test test
test2 test2 test2
test3 test3 test3

key7:



OUTPUT;

        $normalizer = new Normalizer('Y-m-d H:i:s');
        $normalized = $normalizer->normalize($data);
        $this->assertSame($expected, $normalizer->flatten($normalized));
    }
}
