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

class ParentLogHandlerTest extends PHPUnit_Framework_TestCase
{
    public $logger;

    public function setUp()
    {
        $this->logger = new MemoryLogger;
    }

    public function testHandleUsesLastMessageAsParent()
    {
        $handler = new ParentLogHandler($this->logger);

        $records = [
            [
                'message' => 'derp doo',
                'level' => 100,
                'level_name' => 'info',
                'channel' => 'test',
                'datetime' => DateTime::createFromFormat('Y-m-d H:i:s', '2016-01-15 12:00:00', new DateTimeZone('UTC')),
                'context' => ['test1' => 'val'],
                'extra' => []
            ],
            [
                'message' => 'derp dee',
                'level' => 200,
                'level_name' => 'warning',
                'channel' => 'test',
                'datetime' => DateTime::createFromFormat('Y-m-d H:i:s', '2016-01-15 12:00:05', new DateTimeZone('UTC')),
                'context' => [],
                'extra' => []
            ]
        ];
        $handler->handleBatch($records);

        // Note that this is formatted with the default line formatter
        $expectedChildMessageOutput = <<<'OUTPUT'
[2016-01-15 12:00:00] test.info: derp doo {"test1":"val"} []

OUTPUT;

        $message = $this->logger[0];
        $this->assertSame('warning', $message[0]);
        $this->assertSame('derp dee', $message[1]);
        $this->assertSame($expectedChildMessageOutput, $message[2]['exceptionData']);
    }

    public function testMultipleChildMessages()
    {
        $handler = new ParentLogHandler($this->logger);

        $records = [
            [
                'message' => 'derp doo',
                'level' => 500,
                'level_name' => 'critical',
                'channel' => 'test',
                'datetime' => DateTime::createFromFormat('Y-m-d H:i:s', '2016-01-15 12:00:00', new DateTimeZone('UTC')),
                'context' => ['test1' => 'val'],
                'extra' => []
            ],
            [
                'message' => 'derp dee',
                'level' => 200,
                'level_name' => 'warning',
                'channel' => 'test',
                'datetime' => DateTime::createFromFormat('Y-m-d H:i:s', '2016-01-15 12:00:05', new DateTimeZone('UTC')),
                'context' => [],
                'extra' => ['sample' => 'data']
            ],
            [
                'message' => 'parent msg',
                'level' => 250,
                'level_name' => 'notice',
                'channel' => 'test',
                'datetime' => DateTime::createFromFormat('Y-m-d H:i:s', '2016-01-15 12:00:15', new DateTimeZone('UTC')),
                'context' => ['parentcontext' => true],
                'extra' => []
            ]
        ];
        $handler->handleBatch($records);

        // Note that this is formatted with the default line formatter
        $expectedChildMessageOutput = <<<'OUTPUT'
[2016-01-15 12:00:00] test.critical: derp doo {"test1":"val"} []
[2016-01-15 12:00:05] test.warning: derp dee [] {"sample":"data"}

OUTPUT;

        $message = $this->logger[0];
        $this->assertSame('notice', $message[0]);
        $this->assertSame('parent msg', $message[1]);
        $this->assertSame($expectedChildMessageOutput, $message[2]['exceptionData']);
        $this->assertSame(true, $message[2]['parentcontext']);
    }
}
