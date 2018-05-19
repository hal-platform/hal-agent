<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Doctrine\ORM\EntityManagerInterface;
use Hal\Agent\Application\HalClient;
use Hal\Agent\Command\HalCommandFactory;
use Hal\Agent\Executor\Management\RemoveBuildCommand;
use Hal\Agent\Executor\Management\StartBuildCommand;
use Hal\Agent\Executor\Management\StartReleaseCommand;
use Hal\Agent\Executor\Runner\BuildCommand;
use Hal\Agent\Executor\Runner\DeployCommand;
use Hal\Agent\JobConfiguration\ConfigurationReader;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\SSHSessionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Filesystem\Filesystem;

return function (ContainerConfigurator $container) {
    $s = $container->services();

    // Commands
    $s
        ->set(HalCommandFactory::class)
            ->arg('$di', ref('service_container'))

        // Start Build
        ->set('command.job.start_build', Command::class)
            ->factory([ref(HalCommandFactory::class), 'build'])
            ->configurator([StartBuildCommand::class, 'configure'])
            ->arg('$name', 'job:build')
            ->arg('$service', StartBuildCommand::class)
        ->set(StartBuildCommand::class)
            ->arg('$em', ref(EntityManagerInterface::class))
            ->arg('$runner', ref(BuildCommand::class))
            ->arg('$hal', ref(HalClient::class))
            ->public()

        // Start Deploy
        ->set('command.job.start_deploy', Command::class)
            ->factory([ref(HalCommandFactory::class), 'build'])
            ->configurator([StartReleaseCommand::class, 'configure'])
            ->arg('$name', 'job:release')
            ->arg('$service', StartReleaseCommand::class)
        ->set(StartReleaseCommand::class)
            ->arg('$em', ref(EntityManagerInterface::class))
            ->arg('$runner', ref(DeployCommand::class))
            ->arg('$hal', ref(HalClient::class))
            ->public()

        ->set('command.management.remove_build', Command::class)
            ->factory([ref(HalCommandFactory::class), 'build'])
            ->configurator([RemoveBuildCommand::class, 'configure'])
            ->arg('$name', 'management:build:remove')
            ->arg('$service', RemoveBuildCommand::class)
        ->set(RemoveBuildCommand::class)
            ->arg('$em', ref(EntityManagerInterface::class))
            ->arg('$filesystem', ref(Filesystem::class))
            ->call('setArchivePath', ['%path.archive%'])
            ->public()
    ;

    // Run Build
    $s
        ->set('command.job.run_build', Command::class)
            ->factory([ref(HalCommandFactory::class), 'build'])
            ->configurator([BuildCommand::class, 'configure'])
            ->arg('$name', 'runner:build')
            ->arg('$service', BuildCommand::class)
        ->set(BuildCommand::class)
            ->arg('$logger', ref(EventLogger::class))
            ->arg('$cleaner', ref('build.cleaner'))
            ->arg('$sshManager', ref(SSHSessionManager::class))
            ->arg('$resolver', ref('build.resolver'))
            ->arg('$downloader', ref('build.resolver'))
            ->arg('$reader', ref(ConfigurationReader::class))
            ->arg('$builder', ref('build.build_runner'))
            ->arg('$artifacter', ref('build.artifacter'))
            ->call('setShutdownHandler', [true])
            ->public()
    ;

    // Run Deploy
    $s
        ->set('command.job.run_deploy', Command::class)
            ->factory([ref(HalCommandFactory::class), 'build'])
            ->configurator([DeployCommand::class, 'configure'])
            ->arg('$name', 'runner:deploy')
            ->arg('$service', DeployCommand::class)
        ->set(DeployCommand::class)
            ->arg('$logger', ref(EventLogger::class))
            ->arg('$cleaner', ref('deploy.cleaner'))
            ->arg('$resolver', ref('deploy.resolver'))
            ->arg('$artifacter', ref('deploy.artifacter'))
            ->arg('$reader', ref(ConfigurationReader::class))
            ->arg('$builder', ref('deploy.build_runner'))
            ->arg('$deployer', ref('deploy.release_runner'))
            ->call('setShutdownHandler', [true])
            ->public()
    ;
};
