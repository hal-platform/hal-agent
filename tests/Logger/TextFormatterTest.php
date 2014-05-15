<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use DateTime;
use DateTimeZone;
use PHPUnit_Framework_TestCase;

class TextFormatterTest extends PHPUnit_Framework_TestCase
{
    public function testFormatSingleRecord()
    {
        $expected = <<<'OUTPUT'

--------------------------------------------------------------------------------
[2016-01-15 12:00:00] info: test message
--------------------------------------------------------------------------------
test1: val
booltest: 
floattest: 89.3
arraytest: {"multiline":"test\n\ntest\n500","fakemultiline":"meow\nmeow\nbeans","string":"test test test"}
toplevel-multiline:
test

test
500
toplevel-fakemultiline:
meow
meow
beans

OUTPUT;
        $records = [
            [
                'message' => 'test message',
                'level' => 100,
                'level_name' => 'info',
                'channel' => 'test',
                'datetime' => DateTime::createFromFormat('Y-m-d H:i:s', '2016-01-15 12:00:00', new DateTimeZone('UTC')),
                'context' => [
                    'test1' => 'val',
                    'booltest' => false,
                    'floattest' => 89.3,
                    'arraytest' => [
                        'multiline' =>
'test

test
500',
                        'fakemultiline' => "meow\nmeow\nbeans",
                        'string' => 'test test test'
                    ],
                    'toplevel-multiline' =>
'test

test
500',
                    'toplevel-fakemultiline' => "meow\nmeow\nbeans"
                ],
                'extra' => []
            ]
        ];

        $formatter = new TextFormatter;
        $output = $formatter->format($records[0]);
        $this->assertSame($expected, $output);
    }

    public function testFormatBatchRecords()
    {
        $expected = <<<OUTPUT

--------------------------------------------------------------------------------
[2016-01-15 12:00:00] info: derp doo
--------------------------------------------------------------------------------
test1: val

--------------------------------------------------------------------------------
[2016-01-15 12:00:05] warning: derp dee
--------------------------------------------------------------------------------

OUTPUT;

        $records = [
            [
                'message' => 'derp doo',
                'level' => 100,
                'level_name' => 'info',
                'datetime' => DateTime::createFromFormat('Y-m-d H:i:s', '2016-01-15 12:00:00', new DateTimeZone('UTC')),
                'context' => ['test1' => 'val']
            ],
            [
                'message' => 'derp dee',
                'level' => 200,
                'level_name' => 'warning',
                'datetime' => DateTime::createFromFormat('Y-m-d H:i:s', '2016-01-15 12:00:05', new DateTimeZone('UTC')),
                'context' => []
            ]
        ];

        $formatter = new TextFormatter;
        $output = $formatter->formatBatch($records);
        $this->assertSame($expected, $output);
    }
}
