<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;

class Builder
{
    /**
     * @var string
     */
    const SUCCESS_BUILDING = 'Build command successfully run';
    const ERR_BUILDING = 'Build command executed with errors';

    /**
     * @var string
     */
    const CMD_BUILD = 'cd %s && %s 2>&1';

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
     * @param string $command
     * @return boolean
     */
    public function __invoke($buildPath, $command)
    {
        $context = [
            'buildPath' => $buildPath,
            'buildCommand' => $command
        ];

        exec('env', $out, $code);
        $context['environmentVariables'] = $out;

        $command = sprintf(self::CMD_BUILD, $buildPath, $command);
        exec($command, $output, $code);

        // we always want the output
        $context = array_merge($context, ['output' => $output]);

        if ($code === 0) {
            $this->logger->info(self::SUCCESS_BUILDING, $context);
            return true;
        }

        $this->logger->critical(self::ERR_BUILDING, $context);
        return false;
    }
}
