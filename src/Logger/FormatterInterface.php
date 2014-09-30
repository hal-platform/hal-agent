<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

/**
 * Standard Monolog Record:
 *
 * message:     (string)    $message
 * context:     (array)     $context
 * level:       (int)       $level
 * level_name:  (string)    $levelName
 * channel:     (string)    $name
 * datetime:    (DateTime)  $datetime
 * extra:       (array)     $extra
 */
interface FormatterInterface
{
    /**
     * @param array $master
     * @param array $records
     * @return array
     */
    public function format(array $master, array $records);
}
