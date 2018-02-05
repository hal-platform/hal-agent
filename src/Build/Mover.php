<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Logger\EventLogger;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 *
 * TODO REMOVE
 * TODO REMOVE
 * TODO REMOVE    - This was combined into FileCompression and Artifacter
 * TODO REMOVE
 * TODO REMOVE
 *
 */
class Mover
{
    const EVENT_MESSAGE = 'Copy archive to permanent storage';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Filesystem
     */
    private $filesystem;

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
