<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Helper;

/**
 * Concatenate a bunch of strings together.
 *
 * THis is simply used to dynamically construct synthetic services that are really just parameters
 * but Symfony limitations prevent setting those after the container is frozen.
 */
class StringSmusher
{
    public static function smush()
    {
        $args = func_get_args();
        return implode('', $args);
    }
}
