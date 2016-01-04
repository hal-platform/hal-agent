<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push;

use QL\Hal\Agent\Build\Mover as BaseMover;

class Mover extends BaseMover
{
    const EVENT_MESSAGE = 'Copy archive to local storage';
}
