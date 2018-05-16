<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

use GuzzleHttp\Client as GuzzleClient;
use Hal\Agent\Application\HalClient;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\ref;

return function (ContainerConfigurator $container) {
    $container->services()
        ->set('hal.api', HalClient::class)
        ->args([
            ref('hal.api.http_client'),
            [
                'endpoint' => '%hal.baseurl%/api',
                'auth' => '%hal.api_token%'
            ]
        ])
        ->set('hal.api.http_client', GuzzleClient::class)
        ->args([
            [
                'timeout' => '10.0',
                'http_errors' => false,
                'verify' => false
            ]
        ]);
};
