<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Testing;

use QL\Hal\Agent\Push\CodeDeploy\Pusher;

class CodeDeployPusherStub extends Pusher
{
    const WAITER_INTERVAL = 1;
    const WAITER_ATTEMPTS = 10;
}
