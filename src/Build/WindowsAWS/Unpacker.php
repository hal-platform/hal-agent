<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Hal\Agent\Build\Unpacker as BuildUnpacker;

class Unpacker extends BuildUnpacker
{
    const EVENT_MESSAGE = 'Unpack build from build system';
    const ERR_TIMEOUT = 'Unpacking build took too long';
}
