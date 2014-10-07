<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Bootstrap;

use QL\Hal\Agent\CachedContainer;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

require_once __DIR__ . '/../vendor/autoload.php';

// Set Timezone to UTC
ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');
ini_set('memory_limit','384M');

function buildDi($root)
{
    $container = new ContainerBuilder;
    $builder = new YamlFileLoader($container, new FileLocator($root));
    $builder->load('configuration/config.yml');
    $container->compile();

    return $container;
}

function dumpDi(ContainerBuilder $container, $class) {
    $exploded = explode('\\', $class);
    $config = [
        'class' => array_pop($exploded),
        'namespace' => implode('\\', $exploded)
    ];

    return (new PhpDumper($container))->dump($config);
}

function getDi($root)
{
    if (class_exists('QL\Hal\Agent\CachedContainer')) {
        $container = new CachedContainer;

        // Force a fresh container in debug mode
        if ($container->getParameter('debug')) {
            $container = buildDi($root);
        }
    } else {
        $container = buildDi($root);
    }

    $container->set('root', $root);
    return $container;
}
