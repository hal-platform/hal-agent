<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Predis\Client;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Simple\ArrayCache;
use Symfony\Component\Cache\Simple\RedisCache;

return function (ContainerConfigurator $container) {
    $s = $container->services();
    $p = $container->parameters();

    $p
        ->set('cache.redis.namespace', 'halcache')
        ->set('redis.options', ['prefix' => '%redis.prefix%'])
    ;

    $s
        ->set('cache', CacheInterface::class)
            ->factory([ref('service_container'), 'get'])
            ->arg('$id', 'cache.%cache.type.main%')

        ->set('cache.memory', ArrayCache::class)
            ->public()

        ->set('cache.redis', RedisCache::class)
            ->arg('$redisClient', ref(Client::class))
            ->arg('$namespace', '%cache.redis.namespace%')
            ->public()

        ->set(Client::class)
            ->arg('$parameters', '%redis.server%')
            ->arg('$options', '%redis.options%')
    ;

    // Database caching
    $s
        ->set('doctrine.cache.redis', RedisCache::class)
            ->arg('$redisClient', ref(Client::class))
            ->public()
    ;
};
