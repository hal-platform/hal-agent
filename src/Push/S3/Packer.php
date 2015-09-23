<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\S3;

use QL\Hal\Agent\Build\Packer as BuildPacker;

class Packer extends BuildPacker
{
    const EVENT_MESSAGE = 'Pack deployment into archive';
    const ERR_TIMEOUT = 'Packing the archive took too long';
}
