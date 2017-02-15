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
    private function error(StyleInterface $io, $message = '', $exitCode = 1)
    {
        if ($message) {
            $io->error($message);
        }

        return $exitCode;
    }

    private function success(StyleInterface $io, $message)
    {
        if ($message) {
            $io->success($message);
        }

        return 0;
    }
}
