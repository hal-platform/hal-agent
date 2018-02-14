<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Symfony;

use Hal\Agent\Command\IOInterface;

interface IOAwareInterface
{
    /**
     * @param IOInterface|null $io
     *
     * @return void
     */
    public function setIO(?IOInterface $io): void;

    /**
     * @return IOInterface
     */
    public function getIO(): IOInterface;
}
