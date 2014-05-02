<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use PHPUnit_Framework_TestCase;

class LoggerTest extends PHPUnit_Framework_TestCase
{
    public function testGetMessages()
    {
        $logger = new Logger;
        $logger->info('wut');
        $logger->critical('wat', ['data' => 'here']);

        $messages = $logger->messages();

        $this->assertSame(['info', 'wut', []], $messages[0]);
        $this->assertSame(['critical', 'wat', ['data' => 'here']], $messages[1]);
    }

    public function testGetFormattedMessages()
    {
        $logger = new Logger;
        $logger->info('wut');
        $logger->critical('wat', ['data' => 'here']);

        $output = $logger->output();

        $expected = <<<'TEXT'
[INFO]     wut
[CRITICAL] wat

TEXT;

        $this->assertSame($expected, $output);
    }

    public function testGetFormattedMessagesWithContext()
    {
        $logger = new Logger;
        $logger->info('wut');
        $logger->critical('wat', ['data' => 'here']);

        $output = $logger->output(true);

        $expected = <<<'TEXT'
[INFO]     wut
[CRITICAL] wat
{
    "data": "here"
}

TEXT;

        $this->assertSame($expected, $output);
    }

}
