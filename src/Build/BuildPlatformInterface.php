<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Command\IOInterface;

interface BuildPlatformInterface
{
    /**
     * @param string $image
     * @param array $commands
     *                An array of shell commands to run
     * @param array $properties
     *                Build/Release properties
     *
     * @return bool
     */
    public function __invoke(string $image, array $commands, array $properties): bool;

    /**
     * @param IOInterface $io
     *
     * @return void
     */
    public function setIO(IOInterface $io);

    /**
     * @return OutputInterface|null
     */
    public function getIO();
}
