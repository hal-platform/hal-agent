<?php
/**
 * @copyright ©2017 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */
namespace QL\Hal\Agent\Logger;

use QL\MCP\Logger\Service\ErrorLogService;

class McpErrorLogFactory
{
    public static function createMcpErrorLog($projectRoot, array $errorLogOptions = [], $errorLogFile = 'haldev.log', $errorLogSerializer = null)
    {
        $options = array_merge([
            ErrorLogService::CONFIG_FILE => $projectRoot . '/.logs/' . $errorLogFile,
            ErrorLogService::CONFIG_TYPE => ErrorLogService::FILE
        ], $errorLogOptions);

        return new ErrorLogService($errorLogSerializer, $options);
    }
}
