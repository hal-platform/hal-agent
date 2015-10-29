<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\CodeDeploy;

use QL\Hal\Agent\Build\Packer as BuildPacker;

class Packer extends BuildPacker
{
    const EVENT_MESSAGE = 'Pack deployment into revision archive';
    const ERR_TIMEOUT = 'Packing the revision archive took too long';

    // For AWS, deref hardlinks when creating tarball "-h"
    const TAR_FLAGS = '-hvczf';
}
