<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use QL\Hal\Agent\Symfony\OutputAwareTrait;

trait EmergencyBuildHandlerTrait
{
    use OutputAwareTrait;

    /**
     * @type bool
     */
    private $enableShutdownHandler = true;

    /**
     * @type callable|null
     */
    private $emergencyCleaner = null;

    /**
     * In case of error or critical failure, ensure that we clean up the build artifacts.
     *
     * Note that this is only called for exceptions and non-fatal errors.
     * Fatal errors WILL NOT trigger this.
     *
     * @return null
     */
    public function __destruct()
    {
        $this->cleanup();
    }

    /**
     * Emergency failsafe
     *
     * Set or execute the emergency cleanup process
     *
     * @param callable|null $cleaner
     * @return null
     */
    public function cleanup(callable $cleaner = null)
    {
        if (func_num_args() === 1) {
            $this->emergencyCleaner = $cleaner;
        } else {
            if (is_callable($this->emergencyCleaner)) {
                call_user_func($this->emergencyCleaner);
                $this->emergencyCleaner = null;
            }
        }
    }

    /**
     * @return null
     */
    public function disableShutdownHandler()
    {
        $this->enableShutdownHandler = false;
    }

    /**
     * @return void
     */
    private function clean()
    {
        $this->status('Cleaning up build server');

        $this->cleanup();
    }

    /**
     * @param int $exitCode
     *
     * @return int
     */
    private function bombout($exitCode)
    {
        $this->clean();

        return $exitCode;
    }

    /**
     * @param string $user
     * @param string $server
     * @param string $path
     *
     * @return null
     */
    private function enableEmergencyHandler($user, $server, $path)
    {
        if (!property_exists($this, 'cleaner')) {
            return;
        }

        if (!is_callable($this->cleaner)) {
            return;
        }

        $cleaner = $this->cleaner;
        $this->cleanup(function() use ($cleaner, $user, $server, $path) {
            $cleaner($user, $server, $path);
        });

        // Set emergency handler in case of super fatal
        if ($this->enableShutdownHandler) {
            register_shutdown_function([$this, 'cleanup']);
        }
    }
}
