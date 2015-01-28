<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Windows;

use QL\Hal\Agent\Build\BuildHandlerInterface;
use QL\Hal\Agent\Build\PackageManagerPreparer;
use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Console\Output\OutputInterface;

class WindowsBuildHandler implements BuildHandlerInterface
{
    const STATUS = 'Building on windows';
    const SERVER_TYPE = 'windows';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type PackageManagerPreparer
     */
    private $preparer;

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
     * @type bool
     */
    private $enableShutdownHandler;

    /**
     * @type callable|null
     */
    private $emergencyCleaner;

    /**
     * @param EventLogger $logger
     * @param SSHFactory $ssh
     *
     * @param PackageManagerPreparer $preparer
     * @param Exporter $exporter
     * @param Builder $builder
     * @param Importer $importer
     * @param Cleaner $cleaner
     */
    public function __construct(
        EventLogger $logger,
        PackageManagerPreparer $preparer,
        Exporter $exporter,
        Builder $builder,
        Importer $importer,
        Cleaner $cleaner
    ) {
        $this->logger = $logger;

        $this->preparer = $preparer;
        $this->exporter = $exporter;
        $this->builder = $builder;
        $this->importer = $importer;
        $this->cleaner = $cleaner;

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
    public function __invoke(OutputInterface $output, array $properties)
    {
        $this->status($output, self::STATUS);

        // sanity check
        if (!isset($properties[self::SERVER_TYPE])) {
            return 200;
        }

        $this->logger->setStage('building');

        if (!$this->export($output, $properties)) {
            return $this->bombout($output, 201);
        }

        // set package manager config
        if (!$this->prepare($output, $properties)) {
            return $this->bombout($output, 202);
        }

        // run build
        if (!$this->build($output, $properties)) {
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
    private function prepare(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Preparing package manager configuration');

        $preparer = $this->preparer;
        // $preparer(
        //     $properties[self::SERVER_TYPE]['environmentVariables']
        // );

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

        // Set emergency handler in case of super fatal
        $this->enableEmergencyHandler($properties);

        $exporter = $this->exporter;
        return $exporter(
            $properties['location']['path'],
            $properties[self::SERVER_TYPE]['buildServer'],
            $properties[self::SERVER_TYPE]['remotePath']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function build(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Running build command');

        $builder = $this->builder;
        return $builder(
            $properties[self::SERVER_TYPE]['buildServer'],
            $properties[self::SERVER_TYPE]['remotePath'],
            $properties['configuration']['build'],
            $properties[self::SERVER_TYPE]['environmentVariables']
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
        $buildServer = $properties[self::SERVER_TYPE]['buildServer'];
        $remotePath = $properties[self::SERVER_TYPE]['remotePath'];

        $this->cleanup(function() use ($cleaner, $buildServer, $remotePath) {
            $cleaner($buildServer, $remotePath);
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
