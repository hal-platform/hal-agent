<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Symfony\Component\Console\Output\OutputInterface;

trait CommandTrait
{
    /**
     * Override this method to provide custom logic unique to the command
     *
     * @return null
     */
    private function cleanup()
    {
    }

    /**
     * @param OutputInterface $output
     * @param int $exit
     * @return int
     */
    private function failure(OutputInterface $output, $exit = 1)
    {
        // The finish can modify the exit code with custom logic per command
        $message = 'An error occured';
        $exitCode = $this->finish($output, $exit);

        if (isset(static::$codes) && isset(static::$codes[$exitCode])) {
            $message = static::$codes[$exitCode];
        }

        $output->writeln(sprintf('<error>%s</error>', $message));
        return $exitCode;
    }

    /**
     * Override this method to provide custom logic unique to the command
     *
     * @param OutputInterface $output
     * @param int $exitCode
     * @return null
     */
    private function finish(OutputInterface $output, $exitCode)
    {
        $this->cleanup();
        return $exitCode;
    }

    /**
     * @param OutputInterface $output
     * @param string $customMessage
     * @return int
     */
    private function success(OutputInterface $output, $customMessage = null)
    {
        // The finish can modify the exit code with custom logic per command
        $message = 'Success';
        $exitCode = $this->finish($output, 0);

        if (isset(static::$codes) && isset(static::$codes[$exitCode])) {
            $message = static::$codes[$exitCode];
        }

        // Overwrite with custom message if provided
        if ($customMessage !== null) {
            $message = $customMessage;
        }

        $output->writeln(sprintf('<bg=green>%s</bg=green>', $message));
        return $exitCode;
    }
}
