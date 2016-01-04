<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Command\Worker;

use Symfony\Component\Process\Process;

trait WorkerTrait
{
    private $BOOKEND = <<<STDOUT
+----------------------------------------------------------------------------------------------------------------------+\n
STDOUT;

    /**
     * @param string $id
     * @param Process $process
     * @param bool $timedOut
     *
     * @return string
     */
    private function outputJob($id, Process $process, $timedOut = false)
    {
        $exit = $process->getExitCode();
        $stdout = $process->getOutput() . $process->getErrorOutput();

        $status = '<error>error</error>';
        $formattingPad = 15;

        if ($exit === 0) {
            $status = '<info>success</info>';
            $formattingPad = 13;
        }

        if ($timedOut) {
            $status = '<error>timed out</error>';
            $formattingPad = 15;
        }

        $header = sprintf('Build %s finished: %s', $id, $status);

        $output = $this->BOOKEND .
            $this->buildRows($header, 116 + $formattingPad) .
            $this->BOOKEND .
            $this->buildRows($stdout) .
            $this->BOOKEND;

        return $output;
    }

    /**
     * @param string|strings[] $lines
     * @param int $max
     *
     * @return string
     */
    private function buildRows($lines, $max = 116)
    {
        if (!is_array($lines)) {
            $lines = explode("\n", trim($lines));
        }

        $output = '';
        foreach ($lines as $line) {
            $output .= sprintf("| %s |\n", str_pad($line, $max));
        }

        return $output;
    }
}
