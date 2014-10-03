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
    const SUCCESS = 'Application code downloaded';
    const ERR_FAILURE = 'Application code could not be downloaded';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ArchiveApi
     */
    private $github;

    /**
     * @param LoggerInterface $logger
     * @param ArchiveApi $github
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
        $context = [
            'repository' => sprintf('%s/%s', $user, $repo),
            'reference' => $ref,
            'downloadTarget' => $target
        ];

        if ($isSuccessful = $this->github->download($user, $repo, $ref, $target)) {

            $size = filesize($target) / 1048576;
            $context['downloadSize'] = sprintf('%s MB', round($size, 2));

            $this->logger->info(self::SUCCESS, $context);

        } else {
            $this->logger->critical(self::ERR_FAILURE, $context);
        }

        return $isSuccessful;
    }
}
