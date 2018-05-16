<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

use QL\MCP\Cache\CacheInterface;
use QL\MCP\Cache\MemoryCache;
use QL\MCP\Cache\PredisCache;
use Predis\Client as PredisClient;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\ref;

return function (ContainerConfigurator $container) {
    $diAsFactory = [ref('service_container'), 'get'];

    $container->services()
        ->set('cache', CacheInterface::class)
        ->factory($diAsFactory)
        ->args(['cache.%cache.type.main%'])

        ->set('cache.github', CacheInterface::class)
        ->factory($diAsFactory)
        ->public()
        ->args(['cache.%cache.type.github%'])

        ->set('cache.memory', MemoryCache::class)
        ->public()

        ->set('cache.redis', PredisCache::class)
        ->public()
        ->args([ref('redis')])

        ->set('cache.redis_github', PredisCache::class)
        ->public()
        ->parent(ref('cache.redis'))
        ->call('setMaximumTtl', ['%cache.github.default.ttl%'])

        ->set('redis', PredisClient::class)
        ->args([
            '%redis.server%',
            [ 'prefix' => '%redis.prefix%' ]
        ]);
};
