<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\ExceptionHandlerSubscriber;
use Hal\Agent\Logger\MetadataHandler;
use Hal\Agent\Logger\ProcessHandler;
use QL\MCP\Common\Clock;
use QL\MCP\Logger\Logger;

return function (ContainerConfigurator $container) {
    $s = $container->services();
    $p = $container->parameters();

    $p
        ->set('logger.metadata_handler.http_client.options', [
            'base_url' => '%hal.baseurl%',
            'defaults' => [
                'verify' => true,
                'exceptions' => false,
                'headers' => [
                    'Authorization' => 'token %hal.api_token%'
                ]
            ]
        ])
    ;

    $s
        ->set(LoggerInterface::class, Logger::class)
            ->parent(ref('mcp_logger'))
            ->public()

        ->set(EventLogger::class)
            ->arg('$em', ref('mcp_logger'))
            ->arg('$processHandler', ref(ProcessHandler::class))
            ->arg('$metaHandler', ref(MetadataHandler::class))
            ->arg('$clock', ref(Clock::class))

        ->set(ExceptionHandlerSubscriber::class)
            ->arg('$logger', ref(LoggerInterface::class))
            ->call('setStacktraceLogging', ['%error_handling.log_stacktrace%'])

        ->set(ProcessHandler::class)
            ->arg('$em', ref(EntityManagerInterface::class))

        ->set(MetadataHandler::class)
            ->arg('$http', ref('logger.metadata_handler.http_client'))
            ->arg('$logger', ref(LoggerInterface::class))

        ->set('logger.metadata_handler.http_client', Client::class)
            ->arg('$config', '%logger.metadata_handler.http_client.options%')
    ;
};
