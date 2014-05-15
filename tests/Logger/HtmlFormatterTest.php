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

class HtmlFormatterTest extends PHPUnit_Framework_TestCase
{
    public function testFormatSingleRecord()
    {
        $expected = <<<'OUTPUT'
<table cellspacing="1" cellpadding="5" border="0" style="padding: 10px auto;width: 100%">
    <tr style="background: #cccccc;font-weight: bold; font-size: 1.25em;">
        <td style="color: #fff;" width="150">info</td>
        <td style="color: #fff;">test message</td>
    </tr>

    <tr style="background: #eee;">
        <td valign="top">time</td>
        <td colspan="2">
            <pre>2016-01-15 12:00:00</pre>
        </td>
    </tr>

    <tr style="background: #eee;">
        <td valign="top">test1</td>
        <td colspan="2">
            <pre>val</pre>
        </td>
    </tr>

    <tr style="background: #eee;">
        <td valign="top">booltest</td>
        <td colspan="2">
            <pre></pre>
        </td>
    </tr>

    <tr style="background: #eee;">
        <td valign="top">floattest</td>
        <td colspan="2">
            <pre>89.3</pre>
        </td>
    </tr>

    <tr style="background: #eee;">
        <td valign="top">arraytest</td>
        <td colspan="2">
            <pre>{"multiline":"test\n\ntest\n500","fakemultiline":"meow\nmeow\nbeans","string":"test test test"}</pre>
        </td>
    </tr>

    <tr style="background: #eee;">
        <td valign="top">toplevel-multiline</td>
        <td colspan="2">
            <pre>test

test
500</pre>
        </td>
    </tr>

    <tr style="background: #eee;">
        <td valign="top">toplevel-fakemultiline</td>
        <td colspan="2">
            <pre>meow
meow
beans</pre>
        </td>
    </tr>

</table>

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

        $formatter = new HtmlFormatter;
        $output = $formatter->format($records[0]);
        $this->assertSame($expected, $output);
    }

    public function testFormatBatchRecords()
    {
        $expected = <<<OUTPUT
<table cellspacing="1" cellpadding="5" border="0" style="padding: 10px auto;width: 100%">
    <tr style="background: #cccccc;font-weight: bold; font-size: 1.25em;">
        <td style="color: #fff;" width="150">info</td>
        <td style="color: #fff;">derp doo</td>
    </tr>

    <tr style="background: #eee;">
        <td valign="top">time</td>
        <td colspan="2">
            <pre>2016-01-15 12:00:00</pre>
        </td>
    </tr>

    <tr style="background: #eee;">
        <td valign="top">test1</td>
        <td colspan="2">
            <pre>val</pre>
        </td>
    </tr>
    <tr style="background: #468847;font-weight: bold; font-size: 1.25em;">
        <td style="color: #fff;" width="150">warning</td>
        <td style="color: #fff;">derp dee</td>
    </tr>

    <tr style="background: #eee;">
        <td valign="top">time</td>
        <td colspan="2">
            <pre>2016-01-15 12:00:05</pre>
        </td>
    </tr>

</table>

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

        $formatter = new HtmlFormatter;
        $output = $formatter->formatBatch($records);
        $this->assertSame($expected, $output);
    }
}
