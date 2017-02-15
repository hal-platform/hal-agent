<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\StyleInterface;

interface IOInterface extends StyleInterface
{
    /**
     * Returns the argument value for a given argument name.
     *
     * @see Symfony\Component\Console\Input\InputInterface
     */
    public function getArgument($name);

    /**
     * Returns the option value for a given option name.
     *
     * @see Symfony\Component\Console\Input\InputInterface
     */
    public function getOption($name);
}
