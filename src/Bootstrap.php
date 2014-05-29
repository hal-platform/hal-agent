<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Scope;

class Bootstrap
{
    /**
     * @var string
     */
    private $root;

    /**
     * @var ContainerBuilder
     */
    private $container;

    /**
     * @var YamlFileLoader
     */
    private $loader;

    /**
     * @param string $root
     */
    public function __construct($root)
    {
        $this->root = rtrim($root, '/');

        $this->container = new ContainerBuilder;
        $this->container->addScope(new Scope('agent'));

        $locator = new FileLocator([$this->root . '/configuration']);
        $this->loader = new YamlFileLoader($this->container, $locator);
    }

    /**
     * Run the application.
     *
     * @return null
     */
    public function __invoke()
    {
        $app = $this->container()->get('application');
        $app->run();
    }

    /**
     * Get the service container.
     *
     * @return ContainerInterface
     */
    public function container()
    {
        // Freeze the container if not already frozen
        if (!$this->container->isFrozen()) {
            $this->container->compile();
        }

        $this->container->enterScope('agent');

        return $this->container;
    }

    /**
     * Load configuration
     *
     * @return null
     */
    public function load()
    {
        $this->loader->load('config.yml');
        $this->import();
    }

    /**
     * Load external or "imported" configuration
     *
     * @return null
     */
    private function import()
    {
        // dependency installation
        if (isset($_SERVER['HAL_APPLICATION_CONFIG']) && file_exists($_SERVER['HAL_APPLICATION_CONFIG'])) {
            $this->loader->load($_SERVER['HAL_APPLICATION_CONFIG']);
            return;
        }

        // standalone installation
        $this->loader->load('imported.yml');
    }
}
