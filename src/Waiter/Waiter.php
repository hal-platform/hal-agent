<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Waiter;

class Waiter
{
    const ERR_MAX_ATTEMPTS = 'Max attempts reached while waiting for commands to finish. Waited for %d seconds.';

    /**
     * Interval between waits, in seconds.
     *
     * @var float
     */
    private $interval;

    /**
     * @var int
     */
    private $maxAttempts;

    /**
     * @param float $interval
     * @param int $maxAttempts
     */
    public function __construct(float $interval = 10, int $maxAttempts = 60)
    {
        $this->interval = $interval;
        $this->maxAttempts = $maxAttempts;
    }

    /**
     * Will block and repeatedly call a callable until it returns true
     *
     * @param callable $process
     *
     * @throws TimeoutException
     *
     * @return void
     */
    public function wait(callable $process): void
    {
        $attempts = 0;
        do {
            $attempts++;
            if ($attempts > $this->maxAttempts) {
                throw new TimeoutException(sprintf(self::ERR_MAX_ATTEMPTS, $this->interval * $this->maxAttempts));
            }

            if ($process() === true) {
                return;
            }

            usleep($this->interval * 1000000);

        } while (true);
    }
}
