<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use MCP\DataType\Time\TimePoint;
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
     *
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
     *
     * @return null
     */
    private function finish(OutputInterface $output, $exitCode)
    {
        $this->cleanup();

        $this->outputMemoryUsage($output);
        $this->outputTimer($output);

        return $exitCode;
    }

    /**
     * @param OutputInterface $output
     * @param string $customMessage
     *
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

    /**
     * @param OutputInterface $output
     *
     * @return void
     */
    private function outputMemoryUsage(OutputInterface $output)
    {
        // memory usage
        $limit = trim(ini_get('memory_limit'));
        if (1 === preg_match('/([\d]+)M/', $limit, $matches)) {
            $limit = array_pop($matches) * 1024 * 1024;
        }

        $usage = sprintf('%s', round(memory_get_usage() / 1048576, 2));
        $peak = sprintf('%s', round(memory_get_peak_usage(true) / 1048576, 2));
        $limit = sprintf('%s', round($limit / 1048576, 2));

        $memory = sprintf('[<info>Memory Usage</info>] %s / %s MB <comment>(Peak: %s MB)</comment>', $usage, $limit, $peak);
        $output->writeln($memory);
    }

    /**
     * @param OutputInterface $output
     *
     * @return void
     */
    private function outputTimer(OutputInterface $output)
    {
        if (!isset($this->jobStartTime)) {
            return;
        }

        $elapsedSeconds = microtime(true) - $this->jobStartTime;

        $elapsed = '';
        if ($elapsedSeconds > 59) {
            $elapsed = sprintf('%d minutes', floor($elapsedSeconds / 60));
        }

        $seconds = $elapsedSeconds % 60;
        if ($seconds > 0) {
            $elapsed .= sprintf('%d seconds', $seconds);
        }

        $elapsed = sprintf('[<info>Time Elapsed</info>] %s', $elapsed);
        $output->writeln($elapsed);
    }
}
