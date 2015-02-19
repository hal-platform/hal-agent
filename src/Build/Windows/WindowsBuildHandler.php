<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Windows;

use QL\Hal\Agent\Build\BuildHandlerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Utility\EncryptedPropertyResolver;
use Symfony\Component\Console\Output\OutputInterface;

class WindowsBuildHandler implements BuildHandlerInterface
{
    const STATUS = 'Building on windows';
    const SERVER_TYPE = 'windows';
    const ERR_INVALID_BUILD_SYSTEM = 'Windows build system is not configured';
    const ERR_BAD_DECRYPT = 'An error occured while decrypting encrypted properties';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type Exporter
     */
    private $exporter;

    /**
     * @type Builder
     */
    private $builder;

    /**
     * @type Importer
     */
    private $importer;

    /**
     * @type Cleaner
     */
    private $cleaner;

    /**
     * @type EncryptedPropertyResolver
     */
    private $decrypter;

    /**
     * @type bool
     */
    private $enableShutdownHandler;

    /**
     * @type callable|null
     */
    private $emergencyCleaner;

    /**
     * @param EventLogger $logger
     * @param Exporter $exporter
     * @param Builder $builder
     * @param Importer $importer
     * @param Cleaner $cleaner
     * @param EncryptedPropertyResolver $decrypter
     */
    public function __construct(
        EventLogger $logger,
        Exporter $exporter,
        Builder $builder,
        Importer $importer,
        Cleaner $cleaner,
        EncryptedPropertyResolver $decrypter
    ) {
        $this->logger = $logger;

        $this->exporter = $exporter;
        $this->builder = $builder;
        $this->importer = $importer;
        $this->cleaner = $cleaner;
        $this->decrypter = $decrypter;

        $this->enableShutdownHandler = true;
        $this->emergencyCleaner = null;
    }

    /**
     * In case of error or critical failure, ensure that we clean up the build artifacts.
     *
     * Note that this is only called for exceptions and non-fatal errors.
     * Fatal errors WILL NOT trigger this.
     *
     * @return null
     */
    public function __destruct()
    {
        $this->cleanup();
    }

    /**
     * Emergency failsafe
     *
     * Set or execute the emergency cleanup process
     *
     * @param callable|null $cleaner
     * @return null
     */
    public function cleanup(callable $cleaner = null)
    {
        if (func_num_args() === 1) {
            $this->emergencyCleaner = $cleaner;
        } else {
            if (is_callable($this->emergencyCleaner)) {
                call_user_func($this->emergencyCleaner);
                $this->emergencyCleaner = null;
            }
        }
    }

    /**
     * @return null
     */
    public function disableShutdownHandler()
    {
        $this->enableShutdownHandler = false;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(OutputInterface $output, array $commands, array $properties)
    {
        $this->status($output, self::STATUS);

        // sanity check
        if (!$this->sanityCheck($output, $properties)) {
            $this->logger->event('failure', self::ERR_INVALID_BUILD_SYSTEM);
            return 200;
        }

        if (!$this->export($output, $properties)) {
            return $this->bombout($output, 201);
        }

        // decrypt
        $decrypted = $this->decrypt($output, $properties);
        if ($decrypted === null) {
            $this->logger->event('failure', self::ERR_BAD_DECRYPT);
            return 202;
        }

        // run build
        if (!$this->build($output, $properties, $commands, $decrypted)) {
            return $this->bombout($output, 203);
        }

        if (!$this->import($output, $properties)) {
            return $this->bombout($output, 204);
        }

        // success
        return $this->bombout($output, 0);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function sanityCheck(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Validating windows configuration');

        if (!isset($properties[self::SERVER_TYPE])) {
            return false;
        }

        if (!$properties[self::SERVER_TYPE]['buildUser'] || !$properties[self::SERVER_TYPE]['buildServer']) {
            return false;
        }

        return true;
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function export(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Exporting files to build server');

        $exporter = $this->exporter;
        $response = $exporter(
            $properties['location']['path'],
            $properties[self::SERVER_TYPE]['buildUser'],
            $properties[self::SERVER_TYPE]['buildServer'],
            $properties[self::SERVER_TYPE]['remotePath']
        );

        if ($response) {
            // Set emergency handler in case of super fatal
            $this->enableEmergencyHandler($properties);
        }

        return $response;
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return array|null
     */
    private function decrypt(OutputInterface $output, array $properties)
    {
        if (!isset($properties['encrypted'])) {
            return [];
        }

        $decrypted = $this->decrypter->decryptProperties($properties['encrypted']);
        if (count($decrypted) !== count($properties['encrypted'])) {
            return null;
        }

        return $decrypted;
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @param array $commands
     * @param array $decrypted
     *
     * @return boolean
     */
    private function build(OutputInterface $output, array $properties, array $commands, array $decrypted)
    {
        $this->status($output, 'Running build command');

        $env = $properties[self::SERVER_TYPE]['environmentVariables'];

        // merge decrypted properties into env
        if ($decrypted) {
            $env = $this->decrypter->mergePropertiesIntoEnv($env, $decrypted);
        }

        $builder = $this->builder;
        return $builder(
            $properties[self::SERVER_TYPE]['buildUser'],
            $properties[self::SERVER_TYPE]['buildServer'],
            $properties[self::SERVER_TYPE]['remotePath'],
            $commands,
            $env
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function import(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Importing files from build server');

        $importer = $this->importer;
        return $importer(
            $properties['location']['path'],
            $properties[self::SERVER_TYPE]['buildServer'],
            $properties[self::SERVER_TYPE]['remotePath']
        );
    }

    /**
     * @param OutputInterface $output
     *
     * @return boolean
     */
    private function clean(OutputInterface $output)
    {
        $this->status($output, 'Cleaning up build server');

        $this->cleanup();
    }

    /**
     * @param array $properties
     * @return null
     */
    private function enableEmergencyHandler(array $properties)
    {
        $cleaner = $this->cleaner;
        $buildUser = $properties[self::SERVER_TYPE]['buildUser'];
        $buildServer = $properties[self::SERVER_TYPE]['buildServer'];
        $remotePath = $properties[self::SERVER_TYPE]['remotePath'];

        $this->cleanup(function() use ($cleaner, $buildUser, $buildServer, $remotePath) {
            $cleaner($buildUser, $buildServer, $remotePath);
        });

        // Set emergency handler in case of super fatal
        if ($this->enableShutdownHandler) {
            register_shutdown_function([$this, 'cleanup']);
        }
    }

    /**
     * @param OutputInterface $output
     * @param int $exitCode
     *
     * @return int
     */
    private function bombout(OutputInterface $output, $exitCode)
    {
        $this->clean($output);

        return $exitCode;
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
