<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Command\IOInterface;
use Hal\Agent\Command\IO;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Input\ArrayInput;

trait PlatformTrait
{
    use EmergencyBuildHandlerTrait;
    use EnvironmentVariablesTrait;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @param IOInterface|null $output
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

        return new IO(new ArrayInput, new NullOutput);
    }
}
