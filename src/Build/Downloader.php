<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Build;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Github\ArchiveApi;

class Downloader
{
    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Download GitHub archive';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type ArchiveApi
     */
    private $github;

    /**
     * @param EventLogger $logger
     * @param ArchiveApi $github
     */
    public function __construct(EventLogger $logger, ArchiveApi $github)
    {
        $this->logger = $logger;
        $this->github = $github;
    }

    /**
     * @param string $user
     * @param string $repo
     * @param string $ref
     * @param string $target
     * @return boolean
     */
    public function __invoke($user, $repo, $ref, $target)
    {
        if ($isSuccessful = $this->github->download($user, $repo, $ref, $target)) {

            $filesize = filesize($target);
            $this->logger->keep('filesize', ['download' => $filesize]);
            $this->logger->event('success', self::EVENT_MESSAGE, [
                'size' => sprintf('%s MB', round($filesize / 1048576, 2))
            ]);

            return true;
        }

        $this->logger->event('failure', self::EVENT_MESSAGE, [
            'repository' => sprintf('%s/%s', $user, $repo),
            'reference' => $ref,
            'target' => $target
        ]);

        return false;
    }
}
