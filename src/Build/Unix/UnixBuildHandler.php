<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Unix;

use QL\Hal\Agent\Build\BuildHandlerInterface;
use QL\Hal\Agent\Build\Unix\PackageManagerPreparer;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Utility\EncryptedPropertyResolver;
use Symfony\Component\Console\Output\OutputInterface;

class UnixBuildHandler implements BuildHandlerInterface
{
    const STATUS = 'Building on unix';
    const SERVER_TYPE = 'unix';
    const ERR_INVALID_BUILD_SYSTEM = 'Unix build system is not configured';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type PackageManagerPreparer
     */
    private $preparer;

    /**
     * @type Builder
     */
    private $builder;

    /**
     * @type EncryptedPropertyResolver
     */
    private $decrypter;

    /**
     * @param EventLogger $logger
     * @param PackageManagerPreparer $preparer
     * @param builder $builder
     * @param EncryptedPropertyResolver $decrypter
     */
    public function __construct(
        EventLogger $logger,
        PackageManagerPreparer $preparer,
        Builder $builder,
        EncryptedPropertyResolver $decrypter
    ) {
        $this->logger = $logger;
        $this->preparer = $preparer;
        $this->builder = $builder;
        $this->decrypter = $decrypter;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(OutputInterface $output, array $commands, array $properties)
    {
        $this->status($output, self::STATUS);

        // sanity check
        if (!isset($properties[self::SERVER_TYPE])) {
            $this->logger->event('failure', self::ERR_INVALID_BUILD_SYSTEM);
            return 100;
        }

        // set package manager config
        if (!$this->prepare($output, $properties)) {
            return 101;
        }

        // run build
        if (!$this->build($output, $properties, $commands)) {
            return 102;
        }

        // success
        return 0;
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function prepare(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Preparing package manager configuration');

        $preparer = $this->preparer;
        $preparer(
            $properties[self::SERVER_TYPE]['environmentVariables']
        );

        return true;
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @param array $commands
     *
     * @return boolean
     */
    private function build(OutputInterface $output, array $properties, array $commands)
    {
        $this->status($output, 'Running build command');

        $env = $properties[self::SERVER_TYPE]['environmentVariables'];

        // decrypt and add encrypted properties to env if possible
        if (isset($properties['encrypted'])) {
            $env = $this->decrypter->decryptAndMergeProperties($env, $properties['encrypted']);
        }

        $builder = $this->builder;
        return $builder(
            $properties['location']['path'],
            $commands,
            $env
        );
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     *
     * @return null
     */
    private function status(OutputInterface $output, $message)
    {
        $message = sprintf('<comment>%s</comment>', $message);
        $output->writeln($message);
    }
}
