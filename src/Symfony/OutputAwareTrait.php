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
     *
     * @return void
     */
    public function status($message)
    {
        if ($output = $this->getOutput()) {
            $message = sprintf('<comment>%s</comment>', $message);
            $output->writeln($message);
        }
    }
}
