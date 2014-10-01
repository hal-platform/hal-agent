<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

class McpFormatter extends TemplateFormatter
{
    /**
     * Clean the subject for core so that unicode is not in the message
     * {@inheritdoc}
     */
    public function format(array $master, array $records)
    {
        $message = $master['context']['email']['sanitized_subject'];

        $master = parent::format($master, $records);

        $master['context']['exceptionData'] = $master['message'];
        $master['message'] = $message;

        return $master;
    }
}
