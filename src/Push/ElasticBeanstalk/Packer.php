<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Pack the push into a zip for elastic beanstalk.
 *
 * Tars are not supported, so our tar archives must be converted to zip.
 */
class Packer
{
    use ProcessRunnerTrait;

    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Pack deployment into version archive';
    const ERR_TIMEOUT = 'Packing the version archive took too long';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type ProcessBuilder
     */
    private $processBuilder;

    /**
     * @type string
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param ProcessBuilder $processBuilder
     * @param string $commandTimeout
     */
    public function __construct(EventLogger $logger, ProcessBuilder $processBuilder, $commandTimeout)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $buildPath
     * @param string $targetFile
     *
     * @return bool
     */
    public function __invoke($buildPath, $targetFile)
    {
        $cmd = ['zip', '--recurse-paths', $targetFile, '.'];

        $process = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($cmd)
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        if (!$this->runProcess($process, $this->commandTimeout)) {
            return false;
        }

        if ($process->isSuccessful()) {

            $filesize = filesize($targetFile);

            $this->logger->event('success', self::EVENT_MESSAGE, [
                'size' => sprintf('%s MB', round($filesize / 1048576, 2))
            ]);

            return true;
        }

        $dispCommand = implode(' ', $cmd);
        return $this->processFailure($dispCommand, $process);
    }
}
