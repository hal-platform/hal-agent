<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace Hal\Agent\Bootstrap;

require_once __DIR__ . '/../vendor/autoload.php';

// Set Timezone to UTC
ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');
ini_set('memory_limit','384M');
