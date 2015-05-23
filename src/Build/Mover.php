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
     * Provide a list of potential sources. The first source found is used.
     *
     * @param string|string[] $from
     * @param string $to
     *
     * @return bool
     */
    public function __invoke($from, $to)
    {
        if (!is_array($from)) {
            $from = [$from];
        }

        if (!$source = $this->findSource($from)) {
            $this->logger->event('failure', static::EVENT_MESSAGE, [
                'sources' => $from
            ]);

            return false;
        }

        try {
            $this->filesystem->copy($source, $to, true);
        } catch (IOException $e) {
            $this->logger->event('failure', static::EVENT_MESSAGE, [
                'error' => $e->getMessage()
            ]);

            return false;
        }

        $this->logger->event('success', static::EVENT_MESSAGE);
        return true;
    }

    /**
     * @param string[] $sources
     *
     * @return string|null
     */
    private function findSource($sources)
    {
        foreach ($sources as $potentialSource) {
            if ($this->filesystem->exists($potentialSource)) {
                return $potentialSource;
            }
        }

        return null;
    }
}
