<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Testing;

use Hal\Agent\Command\IO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ExecutorTestCase extends TestCase
{
    private $output;

    public function io($configurator, array $args = [])
    {
        // A bit hacky.
        $command = new Command('derp');
        call_user_func([$this, $configurator], $command);

        $input = new ArrayInput($args, $command->getDefinition());
        $output = new BufferedOutput;

        $this->output = $output;

        return new IO($input, $output);
    }

    public function output()
    {
        if ($this->output) {
            return $this->output->fetch();
        }

        return '';
    }

    public function assertContainsLines(array $expected, $actual)
    {
        foreach ($expected as $line) {
            $this->assertContains($line, $actual);
        }
    }
}
