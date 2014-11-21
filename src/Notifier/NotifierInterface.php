<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Notifier;

interface NotifierInterface
{
    /**
     * @param string $event
     * @param string $data
     *
     * @return null
     */
    public function send($event, $data);
}
