<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push;

use Hal\Agent\Build\Mover as BaseMover;

class Mover extends BaseMover
{
    const EVENT_MESSAGE = 'Copy file to prepare upload';
}
