<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor;

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
    private function failure(StyleInterface $io, $message = '', $exitCode = 1)
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
    private function success(StyleInterface $io, $message = '')
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
    private function step($step)
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
        $messages = is_array($message) ? array_values($message) : array($message);

        return array_map(function ($m) use ($type) {
            return sprintf('<%s>%s</%s>', $type, $m, $type);
        }, $messages);
    }
}
