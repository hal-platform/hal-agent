<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Notifier;

interface NotifierInterface
{
    /**
     * @param string $event
     * @param array $data
     *
     * @return null
     */
    public function send($event, array $data);
}
