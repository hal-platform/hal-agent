<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Symfony;

use Symfony\Component\Console\Output\OutputInterface;

trait OutputAwareTrait
{
    /**
     * @type OutputInterface
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
        if ($output = $this->getOutput()) {
            if ($section) {
                $message = sprintf('[<comment>%s</comment>] %s', $section, $message);
            } else {
                $message = sprintf('<comment>%s</comment>', $message);
            }
            $output->writeln($message);
        }
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
