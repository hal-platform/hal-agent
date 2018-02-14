<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Symfony;

use Hal\Agent\Command\IO;
use Hal\Agent\Command\IOInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Input\ArrayInput;

trait IOAwareTrait
{
    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @param IOInterface|null $io
     *
     * @return void
     */
    public function setIO(?IOInterface $io): void
    {
        $this->io = $io;
    }

    /**
     * @return IOInterface
     */
    public function getIO(): IOInterface
    {
        if ($this->io) {
            return $this->io;
        }

        // if IO is not set, we must ensure there is always something output
        // Ideally this never happens. But you know...

        return new IO(new ArrayInput([]), new NullOutput);
    }
}
