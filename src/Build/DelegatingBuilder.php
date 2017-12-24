<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\OutputAwareInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DelegatingBuilder
{
    const PREPARING_BUILD_ENVIRONMENT = 'Prepare build environment';
    const ERR_INVALID_BUILDER = 'Invalid build system specified';
    const UNKNOWN_FAILURE_CODE = 5;
    const DOCKER_PREFIX = 'docker:';

    const ERR_UNKNOWN = 'Unknown build failure';

    const EXIT_CODES = [
        100 => 'Required properties for unix are missing.',
        101 => 'Exporting files to build server failed.',
        102 => 'Encryption failure.',
        103 => 'Build command failed.',
        104 => 'Importing files from build server failed.',

        200 => 'Required properties for windows are missing.',
        201 => 'Exporting files to build server failed.',
        202 => 'Encryption failure.',
        203 => 'Build command failed.',
        204 => 'Importing files from build server failed.',
    ];

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var int
     */
    private $exitCode;

    /**
     * An array of builder handlers
     *
     * Example:
     *     unix => 'service.unix.builder'
     *
     * @var array
     */
    private $builders;

    /**
     * @var boolean
     */
    private $enableStaging;

    /**
     * @param EventLogger $logger
     * @param ContainerInterface $container
     * @param array $builders
     */
    public function __construct(EventLogger $logger, ContainerInterface $container, array $builders = [])
    {
        $this->logger = $logger;
        $this->container = $container;
        $this->builders = $builders;

        $this->exitCode = 0;
        $this->enableStaging = false;
    }

    /**
     * @param StyleInterface $io
     * @param string $system
     * @param array $commands
     * @param array $properties
     *
     * @return bool
     */
    public function __invoke(StyleInterface $io, $system, array $commands, array $properties)
    {
        // reset exit code
        $this->exitCode = 0;

        // Convert "docker:" system to unix
        if (substr($system, 0, 7) === self::DOCKER_PREFIX) {
            $system = 'unix';
        }

        if (!$system || !isset($this->builders[$system])) {
            return $this->explode($system);
        }

        $serviceId = $this->builders[$system];

        // Get the builder
        $builder = $this->container->get($serviceId, ContainerInterface::NULL_ON_INVALID_REFERENCE);

        // Builder must be invokeable
        if (!$builder instanceof BuildHandlerInterface) {
            return $this->explode($system);
        }

        if ($this->enableStaging) {
            $this->logger->setStage('building');
        }

        // Record build environment properties
        if (isset($properties[$system])) {
            $this->logger->event('success', static::PREPARING_BUILD_ENVIRONMENT, $properties[$system]);
        }

        // this sucks!
        if ($builder instanceof OutputAwareInterface && $io instanceof OutputInterface) {
            $builder->setOutput($io);
        }

        $this->exitCode = $builder($commands, $properties);
        return ($this->exitCode === 0);
    }

    /**
     * @param string $system
     *
     * @return bool
     */
    private function explode($system)
    {
        $this->exitCode = static::UNKNOWN_FAILURE_CODE;

        $this->logger->event('failure', self::ERR_INVALID_BUILDER, [
            'system' => $system
        ]);

        return false;
    }

    /**
     * Set whether to start the "building" stage.
     *
     * Do not enable this if building during push.
     *
     * @return void
     */
    public function enableStaging()
    {
        $this->enableStaging = true;
    }

    /**
     * @return string
     */
    public function getFailureMessage()
    {
        return self::EXIT_CODES[$this->exitCode] ?? self::ERR_UNKNOWN;
    }
}
