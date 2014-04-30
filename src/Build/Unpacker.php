<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;

class Unpacker
{
    /**
     * @var string
     */
    const SUCCESS_UNPACK = 'Repository successfully unpacked';
    const SUCCESS_SANITIZED = 'Unpacked archive sanitized successfully';

    const ERR_UNPACK_FAILURE = 'Unable to unpack repository archive';
    const ERR_SANITIZED = 'Unpacked archive could not be sanitized';

    const CMD_UNPACK = 'mkdir %1$s && tar -xzf %2$s --directory=%1$s';
    const CMD_MOVE = <<<'CMD'
cd %1$s && \
find . -name . -o -exec sh -c 'mv "$@" "$0"' ../ {} + -type d -prune && \
cd .. && \
rm -r %1$s
CMD;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var LoggerInterface $logger
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
        $results = glob(sprintf('%s/*', $buildPath), GLOB_ONLYDIR);
        if (is_array($results) && count($results) == 1) {
            return reset($results);
        }

        $this->logger->critical(self::ERR_SANITIZED, $context);
        return null;
    }

    /**
     * @param string $unpackedPath
     * @param array $context
     * @return boolean
     */
    private function sanitizeUnpackedArchive($unpackedPath, array $context)
    {
        $command = sprintf(self::CMD_MOVE, $unpackedPath);
        exec($command, $output, $code);

        if ($code === 0) {
            $this->logger->info(self::SUCCESS_SANITIZED, $context);
            return true;
        }

        $context = array_merge($context, ['output' => $output]);
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
        $command = sprintf(self::CMD_UNPACK, $buildPath, $archive);
        exec($command, $output, $code);

        if ($code === 0) {
            $this->logger->info(self::SUCCESS_UNPACK, $context);
            return true;
        }

        $context = array_merge($context, ['output' => $output]);
        $this->logger->critical(self::ERR_UNPACK_FAILURE, $context);
        return false;
    }
}
