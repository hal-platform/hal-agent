<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class IO extends SymfonyStyle implements IOInterface
{
    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function __construct(InputInterface $input, OutputInterface $output)
    {
        parent::__construct($input, $output);

        $this->input = $input;
    }

    /**
     * Returns the argument value for a given argument name.
     *
     * @see Symfony\Component\Console\Input\InputInterface
     */
    public function getArgument($name)
    {
        return $this->input->getArgument($name);
    }

    /**
     * Returns the option value for a given option name.
     *
     * @see Symfony\Component\Console\Input\InputInterface
     */
    public function getOption($name)
    {
        return $this->input->getOption($name);
    }

    /**
     * Wraps text output in a color
     *
     * @param string $text
     * @param string $foreground
     * @param string $background
     *
     * @return string
     */
    public function color(string $text, string $foreground, ?string $background = null): string
    {
        $pattern = "fg=${foreground}";
        if ($background) {
            $pattern .= ";bg=${background}";
        }

        return "<${pattern}>${text}</>";
    }
}
