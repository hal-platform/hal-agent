<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Waiter;

class Waiter
{
    const ERR_MAX_ATTEMPTS = 'Max attempts reached while waiting for commands to finish. Waited for %d seconds.';

    /**
     * Interval between waits, in seconds.
     *
     * @type float
     */
    private $interval;

    /**
     * @type int
     */
    private $maxAttempts;

    /**
     * @param bool $interval
     * @param bool $attempts
     */
    public function __construct($interval = 10, $maxAttempts = 60)
    {
        $this->interval = (float) $interval;
        $this->maxAttempts = (int) $maxAttempts;
    }

    /**
     * Will block and repeatedly call a callable until it returns true
     *
     * @param callable $process
     *
     * @throws TimeoutException
     *
     * @return null
     */
    public function wait(callable $process)
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
