<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;
use QL\Hal\Agent\Github\ArchiveApi;

class Downloader
{
    /**
     * @var string
     */
    const ERR_DOWNLOAD = 'Github reference "%s" from repository "%s" could not be downloaded!';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ArchiveApi
     */
    private $github;

    /**
     * @var LoggerInterface $logger
     * @var ArchiveApi $github
     */
    public function __construct(LoggerInterface $logger, ArchiveApi $github)
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
        if (!$isSuccessful = $this->github->download($user, $repo, $ref, $target)) {
            $message = sprintf(self::ERR_DOWNLOAD, $ref, sprintf('%s/%s', $user, $repo));
            $this->logger->critical($message);
        }

        return $isSuccessful;
    }
}
