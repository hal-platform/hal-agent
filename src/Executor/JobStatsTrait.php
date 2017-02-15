<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor;

use Hal\Agent\Command\IOInterface;

trait JobStatsTrait
{
    private $jobStartTime;

    private function outputJobStats(IOInterface $io)
    {
        $io->section('Command Usage Statistics');

        [$usage, $peak] = $this->memoryUsage();
        $time = $this->jobTime();

        $io->text([
            sprintf('Memory Usage: <info>%s</info> <comment>(Peak: %s)</comment>', $usage, $peak),
            sprintf('Elapsed Time: <info>%s</info>', $time)
        ]);
    }

    /**
     * @return array
     */
    private function memoryUsage()
    {
        // memory usage
        $limit = trim(ini_get('memory_limit'));
        if (1 === preg_match('/([\d]+)M/', $limit, $matches)) {
            $limit = array_pop($matches) * 1024 * 1024;
        } elseif (1 === preg_match('/([\d]+)G/', $limit, $matches)) {
            $limit = array_pop($matches) * 1024 * 1024 * 1024;
        } else {
            $limit = 0;
        }

        $usage = sprintf('%s', round(memory_get_usage() / 1048576, 2));
        $peak = sprintf('%s', round(memory_get_peak_usage(true) / 1048576, 2));
        $limit = sprintf('%s', round($limit / 1048576, 2));

        return [
            sprintf('%s / %s MB', $usage, $limit),
            sprintf('%s MB', $peak)
        ];
    }

    /**
     * @return void
     */
    private function startTimer()
    {
        $this->jobStartTime = microtime(true);
    }

    /**
     * @return string
     */
    private function jobTime()
    {
        if (!$this->jobStartTime) {
            return 'Unknown';
        }

        $elapsedSeconds = microtime(true) - $this->jobStartTime;

        $elapsed = '';
        if ($elapsedSeconds > 59) {
            $elapsed = sprintf('%d minutes ', floor($elapsedSeconds / 60));
        }

        $seconds = $elapsedSeconds % 60;
        if ($seconds > 0 || !$elapsed) {
            if (!$elapsed && $seconds == 0) {
                $ms = ($elapsedSeconds - floor($elapsedSeconds)) * 100;
                $elapsed .= sprintf('%s ms', floor($ms));
            } else {
                $elapsed .= sprintf('%d seconds', $seconds);
            }
        }

        return trim($elapsed);
    }
}
