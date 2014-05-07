<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Yaml\Dumper;

class Unpacker
{
    /**
     * @var string
     */
    const SUCCESS_UNPACK = 'Repository unpacked';
    const SUCCESS_PROPERTIES = 'Push details written to application directory';

    const ERR_UNPACK_FAILURE = 'Unable to unpack repository archive';
    const ERR_PROPERTIES = 'Push details could not be written: %s';

    /**
     * @var LoggerInterface
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
     * @param LoggerInterface $logger
     * @param ProcessBuilder $processBuilder
     * @param Filesystem $filesystem
     * @param Dumper $dumper
     */
    public function __construct(
        LoggerInterface $logger,
        ProcessBuilder $processBuilder,
        Filesystem $filesystem,
        Dumper $dumper
    ) {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->filesystem = $filesystem;
        $this->dumper = $dumper;
    }

    /**
     * @param string $archive
     * @param string $buildPath
     * @param array $properties
     * @return boolean
     */
    public function __invoke($archive, $buildPath, array $properties)
    {
        $context = [
            'archive' => $archive,
            'buildPath' => $buildPath,
            'pushProperties' => $properties
        ];

        if (!$this->unpackArchive($buildPath, $archive, $context)) {
            return false;
        }

        if (!$this->addBuildDetails($buildPath, $properties, $context)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     * @param array $context
     * @param array $properties
     * @return boolean
     */
    private function addBuildDetails($buildPath, array $properties, array $context)
    {
        $file = sprintf(
            '%s%s%s',
            $buildPath,
            DIRECTORY_SEPARATOR,
            '.hal9000.yml'
        );

        $yml = $this->dumper->dump($properties, 2);

        try {
            $this->filesystem->dumpFile($file, $yml);

        } catch(IOException $exception) {
            $this->logger->critical(sprintf(self::ERR_PROPERTIES, $exception->getMessage()), $context);
            return false;
        }

        $this->logger->info(self::SUCCESS_PROPERTIES, $context);
        return true;
    }

    /**
     * @param string $buildPath
     * @param string $archive
     * @param array $context
     * @return boolean
     */
    private function unpackArchive($buildPath, $archive, array $context)
    {
        $makeCommand = ['mkdir', $buildPath];
        $unpackCommand = ['tar', '-xzf', $archive, sprintf('--directory=%s', $buildPath)];

        $makeProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($makeCommand)
            ->getProcess();

        $unpackProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($unpackCommand)
            ->getProcess();

        // We do it like this so unpack will not be run if makeProcess is not succesful
        if ($makeProcess->run() === 0 && $unpackProcess->run() === 0) {
            $this->logger->info(self::SUCCESS_UNPACK, $context);
            return true;
        }

        $failedCommand = ($unpackProcess->isStarted()) ? $unpackProcess : $makeProcess;
        $context = array_merge($context, [
            'command' => $failedCommand->getCommandLine(),
            'exitCode' => $failedCommand->getExitCode(),
            'output' => $failedCommand->getOutput()
        ]);

        $this->logger->critical(self::ERR_UNPACK_FAILURE, $context);
        return false;
    }
}
