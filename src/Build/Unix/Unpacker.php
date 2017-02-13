<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Unix;

use Hal\Agent\Build\Unpacker as BuildUnpacker;

class Unpacker extends BuildUnpacker
{
    const EVENT_MESSAGE = 'Unpack build from build system';
    const ERR_TIMEOUT = 'Unpacking build took too long';
}
