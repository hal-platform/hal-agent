<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Helper;

/**
 * A simple wrapper around pcntl_fork to allow testing.
 */
class ForkHelper
{
    public function fork()
    {
        return pcntl_fork();
    }
}
