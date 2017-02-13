<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Symfony\OutputAwareTrait;

trait EmergencyBuildHandlerTrait
{
    use OutputAwareTrait;

    /**
     * @var bool
     */
    private $enableShutdownHandler = true;

    /**
     * @var callable|null
     */
    private $emergencyCleaner = null;

    /**
     * @var string
     */
    private $emergencyMessage = '';

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
     * @param string $message
     *
     * @return null
     */
    public function cleanup(callable $cleaner = null, $message = '')
    {
        if (func_num_args() > 0) {
            $this->emergencyCleaner = $cleaner;
            $this->emergencyMessage = $message;

        } elseif (is_callable($this->emergencyCleaner)) {
            if ($this->emergencyMessage) {
                $this->status($this->emergencyMessage, 'Shutdown');
            }

            call_user_func($this->emergencyCleaner);
            $this->emergencyCleaner = null;
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
     * @param int $exitCode
     *
     * @return int
     */
    private function bombout($exitCode)
    {
        $this->cleanup();

        return $exitCode;
    }

    /**
     * @param callable $cleaner
     * @param string $message
     *
     * @param string $user
     * @param string $server
     * @param string $path
     *
     * @return null
     */
    private function enableEmergencyHandler(callable $cleaner, $message, $user, $server, $path)
    {
        $this->cleanup(function() use ($cleaner, $user, $server, $path) {
            $cleaner($user, $server, $path);
        }, $message);

        // Set emergency handler in case of super fatal
        if ($this->enableShutdownHandler) {
            register_shutdown_function([$this, 'cleanup']);
        }
    }
}
