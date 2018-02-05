<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

trait EmergencyBuildHandlerTrait
{
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
     * @param ?string $message
     *
     * @return void
     */
    public function cleanup(callable $cleaner = null, string $message = ''): void
    {
        if (func_num_args() > 0) {
            $this->emergencyCleaner = $cleaner;
            $this->emergencyMessage = $message;

        } elseif (is_callable($this->emergencyCleaner)) {
            if ($this->emergencyMessage) {
                echo "\n\n SHUTDOWN - " . $this->emergencyMessage . "\n\n";
            }

            call_user_func($this->emergencyCleaner);
            $this->emergencyCleaner = null;
        }
    }

    /**
     * @return void
     */
    public function disableShutdownHandler(): void
    {
        $this->enableShutdownHandler = false;
    }

    /**
     * @param bool $isSuccess
     *
     * @return bool
     */
    private function bombout(bool $isSuccess): bool
    {
        $this->cleanup();

        return $isSuccess;
    }

    /**
     * @param callable $cleaner
     * @param ?string $message
     * @param array $args
     *
     * @return void
     */
    private function enableEmergencyHandler(callable $cleaner, ?string $message, array $args = [])
    {
        $this->cleanup(function () use ($cleaner, $args) {
            $cleaner(...$args);
        }, $message);

        // Set emergency handler in case of super fatal
        if ($this->enableShutdownHandler) {
            register_shutdown_function([$this, 'cleanup']);
        }
    }
}
