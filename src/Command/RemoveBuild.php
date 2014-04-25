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
 *  Remove an existing build.
 */
class RemoveBuild extends Command
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
            ->setDescription('Remove an existing build.')
            ->addArgument(
                'BUILD_ID',
                InputArgument::REQUIRED,
                'The build ID to be removed.'
            );
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
        $out->writeln('NYI5');
    }
}
