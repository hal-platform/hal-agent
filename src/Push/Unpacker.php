<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Yaml\Dumper;

class Unpacker
{
    use ProcessRunnerTrait;

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Unpack build archive';

    const ERR_TIMEOUT = 'Unpacking archive took too long';
    const ERR_PROPERTIES = 'Push details could not be written';

    /**
     * @var string
     */
    const FS_DETAILS_FILE = '.hal9000.push.yml';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Dumper
     */
    private $dumper;

    /**
     * @var string
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param ProcessBuilder $processBuilder
     * @param Filesystem $filesystem
     * @param Dumper $dumper
     * @param string $commandTimeout
     */
    public function __construct(
        EventLogger $logger,
        ProcessBuilder $processBuilder,
        Filesystem $filesystem,
        Dumper $dumper,
        $commandTimeout
    ) {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->filesystem = $filesystem;
        $this->dumper = $dumper;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $archive
     * @param string $buildPath
     * @param array $properties
     *
     * @return boolean
     */
    public function __invoke($archive, $buildPath, array $properties)
    {
        if (!$this->unpackArchive($buildPath, $archive)) {
            return false;
        }

        if (!$this->addBuildDetails($buildPath, $properties)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     * @param array $properties
     *
     * @return boolean
     */
    private function addBuildDetails($buildPath, array $properties)
    {
        $file = sprintf(
            '%s%s%s',
            $buildPath,
            DIRECTORY_SEPARATOR,
            self::FS_DETAILS_FILE
        );

        $yml = $this->dumper->dump($properties, 2);

        try {
            $this->filesystem->dumpFile($file, $yml);

        } catch(IOException $exception) {
            $this->logger->event('failure', self::ERR_PROPERTIES, [
                'error' => $exception->getMessage()
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     * @param string $archive
     *
     * @return boolean
     */
    private function unpackArchive($buildPath, $archive)
    {
        $makeCommand = ['mkdir', $buildPath];
        $unpackCommand = ['tar', '-vxzf', $archive, sprintf('--directory=%s', $buildPath)];

        $makeProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($makeCommand)
            ->getProcess();

        $unpackProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($unpackCommand)
            ->setTimeout($this->commandTimeout)
            ->getProcess();

        // We do it like this so unpack will not be run if makeProcess is not succesful
        if ($makeProcess->run() === 0) {
            if (!$this->runProcess($unpackProcess, $this->commandTimeout)) {
                return false;
            }

            if ($unpackProcess->isSuccessful()) {
                return true;
            }
        }

        $failedCommand = ($unpackProcess->isStarted()) ? $unpackProcess : $makeProcess;

        $dispCommand = [
            implode(' ', $makeCommand),
            implode(' ', $unpackCommand)
        ];

        return $this->processFailure($dispCommand, $failedCommand);
    }
}
