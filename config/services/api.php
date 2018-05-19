<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use GuzzleHttp\Client;
use Hal\Agent\Application\HalClient;

return function (ContainerConfigurator $container) {
    $s = $container->services();
    $p = $container->parameters();

    $p
        ->set('hal_api.options', [
            'endpoint' => '%hal.baseurl%/api',
            'auth' => '%hal.api_token%'
        ])
        ->set('hal_api.http_options', [
            'timeout' => '10.0',
            'http_errors' => false,
            'verify' => false
        ])
    ;

    $s
        ->set(HalClient::class)
            ->arg('$guzzle', ref('hal_api.http_client'))
            ->arg('$config', '%hal_api.options%')

        ->set('hal_api.http_client', Client::class)
            ->arg('$config', '%hal_api.http_options%')
    ;
};
