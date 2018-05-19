<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Aws\Sdk;
use Aws\Credentials\CredentialProvider;

return function (ContainerConfigurator $container) {
    $s = $container->services();
    $p = $container->parameters();

    $p
        ->set('aws.sdk_version', 'latest')
    ;

    $s
        ->set(Sdk::class)
            ->arg('$args', [
                'version' => '%aws.sdk_version%'
            ])

        ->set('aws.host_sdk_credential_provider', CredentialProvider::class)
            ->factory([CredentialProvider::class, 'ini'])
            ->arg('$profile', null)
            ->arg('$filename', '%aws.host_credentials_path%')
    ;
};
