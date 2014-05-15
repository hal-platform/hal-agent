<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Monolog\Handler\BufferHandler as BaseBufferHandler;

/**
 * This is a custom buffer handler which does not attach any callbacks to onshutdown.
 *
 * To trigger log handling, it MUST be flushed.
 */
class BufferHandler extends BaseBufferHandler
{
    protected $initialized = true;
}
