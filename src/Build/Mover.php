<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class Mover
{
    const EVENT_MESSAGE = 'Copy archive to permanent storage';

    /**
     * @param EventLogger $logger
     * @param Filesystem $filesystem
     */
    public function __construct(EventLogger $logger, Filesystem $filesystem)
    {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
    }

    /**
     * @param string $tempArchive
     * @param string $archive
     *
     * @return bool
     */
    public function __invoke($tempArchive, $archive)
    {
        try {
            $this->filesystem->copy($tempArchive, $archive, true);
        } catch (IOException $e) {
            $this->logger->event('failure', self::EVENT_MESSAGE, [
                'error' => $e->getMessage()
            ]);

            return false;
        }

        $this->logger->event('success', self::EVENT_MESSAGE);
        return true;
    }
}
