<?php

namespace Hal\Agent\Bootstrap;

require_once __DIR__ . '/../vendor/autoload.php';

// Set Timezone to UTC
ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');
ini_set('memory_limit','384M');
