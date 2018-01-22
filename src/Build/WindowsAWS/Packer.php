<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Hal\Agent\Build\Packer as BuildPacker;

class Packer extends BuildPacker
{
    const EVENT_MESSAGE = 'Pack source for build system';
    const ERR_TIMEOUT = 'Packing the source took too long';
}
