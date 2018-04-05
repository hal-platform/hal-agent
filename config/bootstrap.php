<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Bootstrap;

use Hal\Agent\Application\DI;
use Hal\Agent\CachedContainer;
use Symfony\Component\Dotenv\Dotenv;

$root = realpath(__DIR__ . '/..');
require_once "${root}/vendor/autoload.php";

if (!ini_get('date.timezone')) {
    ini_set('date.timezone', 'UTC');
}

if (file_exists("${root}/config/.env")) {
    $dotenv = new Dotenv;
    $dotenv->load("${root}/config/.env");
}

$file = "${root}/src/CachedContainer.php";
$class = CachedContainer::class;
$options = [
    'class' => $class,
    'file' => $file
];

return DI::getDI($root, $options);
