<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use QL\Hal\Agent\Build\Mover as BaseMover;

class Mover extends BaseMover
{
    const EVENT_MESSAGE = 'Copy archive to local storage';
}
