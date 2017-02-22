<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Application;

use Hal\Agent\Application\Config\HalCoreExtension;
use Hal\Agent\Application\Config\McpLoggerExtension;
use Hal\Agent\CachedContainer;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Stolen from:
 *     - ql/panthor
 *     - QL\Panthor\Bootstrap\Di
 */
class Di
{
    /**
     * The primary configuration file. The entry point of all configuration.
     *
     * @var string
     */
    const PRIMARY_CONFIGURATION_FILE = 'configuration/config.yml';

    /**
     * The parameter to check whether the container should be cached.
     *
     * Using a prefix of "@" will check a service instead of parameter.
     *
     * @var string
     */
    const KEY_PREVENT_CACHING = 'debug';

    /**
     * Service key for root path
     *
     * @var string
     */
    const KEY_ROOT_PATH = 'root';

    public static function buildHalDI($root)
    {
        $extensions = [new HalCoreExtension, new McpLoggerExtension];
        $container = self::buildDi(
            $root,
            function (ContainerBuilder $di) use ($extensions) {
                (new EnvConfigLoader)->load($di);
                foreach ($extensions as $ext) {
                    $di->registerExtension($ext);
                }
            },
            function (ContainerBuilder $di) use ($extensions) {
                foreach ($extensions as $ext) {
                    $di->loadFromExtension($ext->getAlias());
                }
            }
        );

        return $container;
    }

    public static function getHalDI($root)
    {
        $extensions = [new HalCoreExtension, new McpLoggerExtension];

        $container = self::getDi(
            $root,
            CachedContainer::class,
            function (ContainerBuilder $di) use ($extensions) {
                (new EnvConfigLoader)->load($di);
                foreach ($extensions as $ext) {
                    $di->registerExtension($ext);
                }
            },
            function (ContainerBuilder $di) use ($extensions) {
                foreach ($extensions as $ext) {
                    $di->loadFromExtension($ext->getAlias());
                }
            }
        );

        return $container;
    }

    /**
     * @param string $root The application root directory.
     * @param callable $preLoad
     * @param callable $postLoad
     *
     * @return ContainerBuilder
     * @internal param callable $containerModifier Modify the container with a callable before it is compiled.
     *
     */
    public static function buildDi($root, callable $preLoad = null, callable $postLoad = null)
    {
        $container = new ContainerBuilder;
        $builder = new YamlFileLoader($container, new FileLocator($root));

        if (is_callable($preLoad)) {
            $preLoad($container);
        }

        $builder->load(static::PRIMARY_CONFIGURATION_FILE);

        if (is_callable($postLoad)) {
            $postLoad($container);
        }

        $container->compile();

        return $container;
    }

    /**
     * @param ContainerBuilder $container  The built container, ready for caching.
     * @param string           $class      Fully qualified class name of the cached container.
     * @param string           $baseClass  Optionally pass a base_class for the cached container.
     *
     * @return string The cached container file contents.
     */
    public static function dumpDi(ContainerBuilder $container, $class, $baseClass = null)
    {
        $exploded = explode('\\', $class);
        $config = [
            'class' => array_pop($exploded),
            'namespace' => implode('\\', $exploded)
        ];

        if ($baseClass) {
            $config['base_class'] = $baseClass;
        }

        return (new PhpDumper($container))->dump($config);
    }

    /**
     * @param  string $root The application root directory.
     * @param  string $class Fully qualified class name of the cached container.
     * @param callable $preLoad
     * @param callable $postLoad
     *
     * @return ContainerInterface A service container. This may or may not be a cached container.
     */
    public static function getDi($root, $class, callable $preLoad = null, callable $postLoad = null)
    {
        $root = rtrim($root, '/');

        if (class_exists($class)) {
            $container = new $class;

            // Force a fresh container in debug mode
            if (static::shouldRefreshContainer($container)) {
                $container = static::buildDi($root, $preLoad, $postLoad);
            }

        } else {
            $container = static::buildDi($root, $preLoad, $postLoad);
        }

        // Set the synthetic root service. This must not ever be cached.
        $container->set(static::KEY_ROOT_PATH, $root);

        return $container;
    }

    /**
     * @param ContainerInterface $container
     *
     * @return bool
     */
    protected static function shouldRefreshContainer(ContainerInterface $container)
    {
        $lookup = static::KEY_PREVENT_CACHING;
        $isService = substr($lookup, 0, 1) === '@';
        if ($isService) {
            $lookup = substr($lookup, 1);
        }

        if ($isService) {
            return $container->has($lookup) && $container->get($lookup);
        }

        return $container->hasParameter($lookup) && $container->getParameter($lookup);
    }
}
