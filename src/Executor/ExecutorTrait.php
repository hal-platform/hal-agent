<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor;

use Hal\Agent\Command\IO;
use Hal\Agent\Command\IOInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\StyleInterface;

trait ExecutorTrait
{
    /**
     * @param StyleInterface $io
     * @param string $message
     * @param int $exitCode
     *
     * @return int
     */
    private function failure(StyleInterface $io, $message = '', $exitCode = 1): int
    {
        if ($message) {
            $io->error($message);
        }

        return $exitCode;
    }

    /**
     * @param StyleInterface $io
     * @param string $message
     *
     * @return int
     */
    private function success(StyleInterface $io, $message = ''): int
    {
        if ($message) {
            $io->success($message);
        }

        return 0;
    }

    /**
     * @param int $step
     *
     * @return string
     */
    private function step($step): string
    {
        $max = count(self::STEPS);
        $msg = self::STEPS[$step] ?? 'Unknown';

        return sprintf('[<info>%s/%s</info>] %s', $step, $max, $msg);
    }

    /**
     * @param string|array $message
     * @param int $step
     *
     * @return string
     */
    private function colorize($message, $type = 'info')
    {
        $messages = is_array($message) ? array_values($message) : [$message];

        $output = array_map(function ($m) use ($type) {
            return sprintf('<%s>%s</%s>', $type, $m, $type);
        }, $messages);

        if (!is_array($message)) {
            return $output[0];
        }

        return $output;
    }

    /**
     * @param array $parameters
     *
     * @return IOInterface
     */
    private function buildIO(array $parameters = []): IOInterface
    {
        $def = null;
        if ($parameters) {
            $def = new InputDefinition(array_map(function ($v) {
                return new InputArgument($v);
            }, array_keys($parameters)));
        }

        return new IO(
            new ArrayInput($parameters, $def),
            new ConsoleOutput
        );
    }
}
