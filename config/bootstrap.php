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

$root = __DIR__ . '/..';
require_once "${root}/vendor/autoload.php";

if (!ini_get('date.timezone')) {
    ini_set('date.timezone', 'UTC');
}

$dotenv = new Dotenv;

if (file_exists("${root}/config/.env")) {
    $dotenv->load("${root}/config/.env");
}

if (file_exists("${root}/config/.env.default")) {
    $dotenv->load("${root}/config/.env.default");
}

$file = "${root}/src/CachedContainer.php";
$class = CachedContainer::class;
$options = [
    'class' => $class,
    'file' => $file
];

return DI::getDI($root, $options);
