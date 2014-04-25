<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *  List existing builds for an application
 */
class ListBuilds extends Command
{
    /**
     * @param string $name
     */
    public function __construct($name)
    {
        parent::__construct($name);
    }

    /**
     *  Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('List existing builds.');
    }

    /**
     *  Run the command
     *
     *  @param InputInterface $in
     *  @param OutputInterface $out
     *  @return void
     */
    protected function execute(InputInterface $in, OutputInterface $out)
    {
        $out->writeln('NYI3');
    }
}
