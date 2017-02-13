<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Symfony;

use PHPUnit_Framework_TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class ContainerIntegrationTest extends PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        //kind of lame
        $configRoot = __dir__ . '/../../../configuration';
        if (!file_exists($configRoot . '/config.env.yml')) {
            touch($configRoot . '/config.env.yml');
            copy($configRoot . '/environment/dev.yml', $configRoot . '/config.env.yml');
        }
    }

    /**
     * Tests to make sure a parse exception isn't thrown on our yaml configs
     */
    public function testContainerCompiles()
    {
        $configRoot = __dir__ . '/../../../configuration';
        $container = new ContainerBuilder();
        $builder = new YamlFileLoader($container, new FileLocator($configRoot));

        $builder->load($configRoot . '/config.yml');

        $container->compile();
    }
}
