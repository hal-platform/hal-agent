<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Doctrine\ORM\EntityManagerInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\CredentialWallet;
use Hal\Agent\Remoting\FileSyncManager;
use Hal\Agent\Remoting\SSHSessionManager;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\Crypto\CryptoFilesystemFactory;
use Hal\Core\Crypto\Encryption;

return function (ContainerConfigurator $container) {
    $s = $container->services();

    // Remoting
    $s
        ->set(SSHSessionManager::class)
            ->arg('$logger', ref(EventLogger::class))
            ->arg('$credentials', ref(CredentialWallet::class))

        ->set(FileSyncManager::class)
            ->arg('$credentials', ref(CredentialWallet::class))

        ->set(CredentialWallet::class)
            ->call('importCredentials', ['%ssh.credentials%'])
    ;

    // Encryption
    $s
        ->set(Encryption::class)
            ->factory([ref(CryptoFilesystemFactory::class), 'getCrypto'])
            ->lazy()

        ->set(CryptoFilesystemFactory::class)
            ->arg('$keyPath', ref(CredentialWallet::class))

        ->set(EncryptedPropertyResolver::class)
            ->arg('$em', ref(EntityManagerInterface::class))
            ->arg('$encryption', ref(Encryption::class))
            ->arg('$logger', ref(EventLogger::class))
    ;
};
