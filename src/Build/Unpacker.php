<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class Unpacker
{
    /**
     * @var string
     */
    const SUCCESS_UNPACK = 'Repository unpacked';
    const SUCCESS_LOCATED = 'Unpacked archive located';
    const SUCCESS_SANITIZED = 'Unpacked archive sanitized';

    const ERR_UNPACK_FAILURE = 'Unable to unpack repository archive';
    const ERR_LOCATED = 'Unpacked archive could not be located';
    const ERR_SANITIZED = 'Unpacked archive could not be sanitized';

    /**
     * @var string
     */
    const CMD_UNPACK = 'mkdir %1$s && tar -xzf %2$s --directory=%1$s';
    const CMD_REMOVE = 'rm -r %s';
    const CMD_MOVE = 'mv * .[^.]* ..';

    // this command will fail if there is more than 1 child directory because the wildcard
    // is expanded prior to execution. This is a good thing. We expect github archives
    // to unpack a single directory
    const CMD_GLOB = 'find %s -name * -type d';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $archive
     * @param string $buildPath
     * @return boolean
     */
    public function __invoke($archive, $buildPath)
    {
        $context = [
            'archive' => $archive,
            'buildPath' => $buildPath
        ];

        if (!$this->unpackArchive($buildPath, $archive, $context)) {
            return false;
        }

        if (!$unpackedPath = $this->locateUnpackedArchive($buildPath, $context)) {
            return false;
        }

        if (!$this->sanitizeUnpackedArchive($unpackedPath, $context)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     * @param array $context
     * @return string|null
     */
    private function locateUnpackedArchive($buildPath, array $context)
    {
        $command = sprintf(self::CMD_GLOB, $buildPath);
        $process = new Process($command, $buildPath);
        $process->run();

        if ($process->isSuccessful()) {
            $this->logger->info(self::SUCCESS_LOCATED, $context);
            return trim($process->getOutput());
        }

        $this->logger->critical(self::ERR_LOCATED, $context);
        return null;
    }

    /**
     * @param string $unpackedPath
     * @param array $context
     * @return boolean
     */
    private function sanitizeUnpackedArchive($unpackedPath, array $context)
    {
        $process = new Process(self::CMD_MOVE, $unpackedPath);
        $process->run();

        if ($process->isSuccessful()) {
            $this->logger->info(self::SUCCESS_SANITIZED, $context);

            // remove unpacked directory
            $removal = new Process(sprintf(self::CMD_REMOVE, $unpackedPath));
            $removal->run();

            return true;
        }

        $context = array_merge($context, ['output' => $process->getOutput()]);
        $this->logger->critical(self::ERR_SANITIZED, $context);
        return false;
    }

    /**
     * @param string $buildPath
     * @param string $archive
     * @param array $context
     * @return boolean
     */
    private function unpackArchive($buildPath, $archive, array $context)
    {
        $process = new Process(
            sprintf(self::CMD_UNPACK, $buildPath, $archive)
        );
        $process->run();

        if ($process->isSuccessful()) {
            $this->logger->info(self::SUCCESS_UNPACK, $context);
            return true;
        }

        $context = array_merge($context, ['output' => $process->getOutput()]);
        $this->logger->critical(self::ERR_UNPACK_FAILURE, $context);
        return false;
    }
}
