<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class Packer
{
    /**
     * @var string
     */
    const SUCCESS_PACKED = 'Build archived';
    const ERR_PACKED = 'Build archive did not pack correctly';

    /**
     * @var string
     */
    const CMD_UNPACK = 'tar -czf %s .';

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

        $process = new Process(sprintf(self::CMD_UNPACK, $targetFile), $buildPath);
        $process->run();

        if ($process->isSuccessful()) {
            $this->logger->info(self::SUCCESS_PACKED, $context);
            return true;
        }

        $context = array_merge($context, ['output' => $process->getOutput()]);
        $this->logger->critical(self::ERR_PACKED, $context);
        return false;
    }
}
