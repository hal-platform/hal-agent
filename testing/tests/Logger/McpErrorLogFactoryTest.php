<?php
/**
 * @copyright ©2017 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */
namespace QL\Hal\Agent\Logger;

use PHPUnit_Framework_TestCase;
use QL\MCP\Logger\Service\ErrorLogService;

class McpErrorLogFactoryTest extends PHPUnit_Framework_TestCase
{
    public function testReturnIsErrorLoggerService()
    {
        $this->assertInstanceOf(ErrorLogService::class, McpErrorLogFactory::createMcpErrorLog(''));
    }
}
