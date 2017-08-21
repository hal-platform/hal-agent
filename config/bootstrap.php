<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Bootstrap;

use Hal\Agent\Application\DI2;
use Hal\Agent\CachedContainer;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\DependencyInjection\ContainerBuilder;

$root = realpath(__DIR__ . '/..');
require_once "${root}/vendor/autoload.php";

$dotenv = new Dotenv;
$dotenv->load("${root}/config/.env");

$file = "${root}/src/CachedContainer.php";
$class = CachedContainer::class;
$options = [
    'class' => $class,
    'file' => $file
];

return DI2::getDI($root, $options);
