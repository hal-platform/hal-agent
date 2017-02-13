<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Github\ArchiveApi;
use Hal\Agent\Github\GitHubException;

class Downloader
{
    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Download GitHub archive';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ArchiveApi
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
        try {
            $isSuccessful = $this->github->download($user, $repo, $ref, $target);
        } catch (GitHubException $ex) {
            $isSuccessful = false;
        }

        if ($isSuccessful) {
            $filesize = filesize($target);
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
