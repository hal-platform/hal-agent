<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\Script;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Command\IO;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DeployDelegatingBuilder
{
    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ContainerInterface
     */
    private $di;

    /**
     * @var array
     */
    private $platforms;

    /**
     * @param EventLogger $logger
     */
    public function __construct(
        EventLogger $logger,
        ContainerInterface $di,
        $platforms
    ) {
        $this->logger = $logger;
        $this->di = $di;
        $this->platforms = $platforms;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(
        IO $io,
        $platform,
        $image,
        $deploy,
        $properties
    ) {
        return false;
    }
}
