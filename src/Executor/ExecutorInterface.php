<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor;

use Hal\Agent\Command\IOInterface;
use Symfony\Component\Console\Command\Command;

interface ExecutorInterface
{
    /**
     * Configure the command.
     *
     * Set the command definition such as expected arguments, flags, or help text.
     *
     * @return void
     */
    public static function configure(Command $command);

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io);
}
