<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Symfony;

use Symfony\Component\Console\Output\OutputInterface;

trait OutputAwareTrait
{
    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @param OutputInterface $output
     *
     * @return void
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * @return OutputInterface|null
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * @param string $message
     * @param string $section
     *
     * @return void
     */
    public function status($message, $section = '')
    {
        if (!$output = $this->getOutput()) {
            return;
        }

        if ($section) {
            $message = sprintf('[<comment>%s</comment>] %s', $section, $message);
        } else {
            $message = sprintf('<comment>%s</comment>', $message);
        }

        $output->writeln($message);
    }

    /**
     * @param string $stdout
     *
     * @return void
     */
    private function write($stdout)
    {
        if ($output = $this->getOutput()) {
            $output->writeln($stdout);
        }
    }
}
