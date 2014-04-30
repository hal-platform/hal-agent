<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;

class Packer
{
    /**
     * @var string
     */
    const SUCCESS_PACKED = 'Build successfully archived';
    const ERR_PACKED = 'Build archive did not pack correctly';

    /**
     * @var string
     */
    const CMD_UNPACK = 'cd %s && tar -czf %s .';

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
     * @param string $buildPath
     * @param string $targetFile
     * @return boolean
     */
    public function __invoke($buildPath, $targetFile)
    {
        $context = [
            'buildPath' => $buildPath,
            'archive' => $targetFile
        ];

        $command = sprintf(self::CMD_UNPACK, $buildPath, $targetFile);
        exec($command, $output, $code);

        if ($code === 0) {
            $this->logger->info(self::SUCCESS_PACKED, $context);
            return true;
        }

        $context = array_merge($context, ['output' => $output]);
        $this->logger->critical(self::ERR_PACKED, $context);
        return false;
    }
}
