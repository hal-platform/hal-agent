<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Unix;

use QL\Hal\Agent\Build\BuildHandlerInterface;
use QL\Hal\Agent\Build\PackageManagerPreparer;
use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Console\Output\OutputInterface;

class UnixBuildHandler implements BuildHandlerInterface
{
    const STATUS = 'Building on unix';
    const SERVER_TYPE = 'unix';

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
     * @param EventLogger $logger
     * @param PackageManagerPreparer $preparer
     * @param builder $builder
     */
    public function __construct(
        EventLogger $logger,
        PackageManagerPreparer $preparer,
        Builder $builder
    ) {
        $this->logger = $logger;
        $this->preparer = $preparer;
        $this->builder = $builder;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(OutputInterface $output, array $properties)
    {
        $this->status($output, self::STATUS);

        // sanity check
        if (!isset($properties[self::SERVER_TYPE])) {
            return 100;
        }

        $this->logger->setStage('building');

        // set package manager config
        if (!$this->prepare($output, $properties)) {
            return 101;
        }

        // run build
        if (!$this->build($output, $properties)) {
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
     *
     * @return boolean
     */
    private function build(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Running build command');

        $builder = $this->builder;
        return $builder(
            $properties['location']['path'],
            $properties['configuration']['build'],
            $properties[self::SERVER_TYPE]['environmentVariables']
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
