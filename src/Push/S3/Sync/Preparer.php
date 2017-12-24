<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3\Sync;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Push\ReleasePacker;
use Hal\Agent\Utility\ProcessRunnerTrait;
use Hal\Agent\Utility\SourcePathBuilderTrait;
use Hal\Agent\Push\PushException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ProcessBuilder;

class Preparer
{
    use ProcessRunnerTrait;
    use SourcePathBuilderTrait;

    const EVENT_MESSAGE = 'Prepare artifact for S3';

    const ERR_DIST_NOT_VALID = 'Cannot find dist directory';
    const ERR_TIMEOUT = 'Validating files to upload took too long';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var string
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param Filesystem $filesystem
     * @param ProcessBuilder $processBuilder
     * @param string $commandTimeout
     */
    public function __construct(
        EventLogger $logger,
        Filesystem $filesystem,
        ProcessBuilder $processBuilder,
        $commandTimeout
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->processBuilder = $processBuilder;

        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $buildPath
     * @param string $distPath
     *
     * @return bool
     */
    public function __invoke($buildPath, $distPath)
    {
        $wholeSourcePath = $this->getWholeSourcePath($buildPath, $distPath);

        // check if exists
        if (!$this->filesystem->exists($wholeSourcePath)) {
            $this->logger->event('failure', self::ERR_DIST_NOT_VALID, ['path' => $distPath]);
            return false;
        }

        // check if directory
        try {
            $isDir = $this->isDirectory($wholeSourcePath);
        } catch (PushException $e) {
            return false;
        }

        return $isDir;
    }

    /**
     * @param string $sourcePath
     *
     * @throws PushException
     *
     * @return bool
     */
    private function isDirectory($sourcePath)
    {
        // check if dir
        $isDirCommand = ['test', '-d', $sourcePath];
        $process = $this->processBuilder
            ->setArguments($isDirCommand)
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        if (!$this->runProcess($process, $this->commandTimeout)) {
            $this->logger->event('failure', self::ERR_TIMEOUT, []);
            throw new PushException(self::ERR_TIMEOUT);
        }

        return $process->isSuccessful();
    }
}
