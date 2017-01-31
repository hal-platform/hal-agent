<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\Script;

use QL\Hal\Agent\Build\DelegatingBuilder;
use QL\Hal\Agent\Push\DeployerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
use QL\Hal\Core\Type\EnumType\ServerEnum;

class Deployer implements DeployerInterface, OutputAwareInterface
{
    use OutputAwareTrait;

    const SECTION = 'Deploying - Script';
    const STATUS = 'Deploying push by script';

    const ERR_INVALID_DEPLOYMENT_SYSTEM = 'Script deployment system is not configured';
    const ERR_NO_DEPLOY_SCRIPTS = 'No deployment scripts are defined';
    const ERR_DEPLOY_COMMAND_FAILED = 'No deployment scripts are defined';

    const SKIP_PRE_PUSH = 'Skipping pre-push commands for Script deployment';
    const SKIP_POST_PUSH = 'Skipping post-push commands for Script deployment';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var DelegatingBuilder
     */
    private $builder;

    /**
     * @param EventLogger $logger
     * @param DelegatingBuilder $builder
     */
    public function __construct(EventLogger $logger, DelegatingBuilder $builder)
    {
        $this->logger = $logger;
        $this->builder = $builder;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(array $properties)
    {
        $this->status(self::STATUS, self::SECTION);

        // sanity check
        if (!$this->sanityCheck($properties)) {
            $this->logger->event('failure', self::ERR_INVALID_DEPLOYMENT_SYSTEM);
            return 300;
        }

        // no deploy scripts defined
        if (!$properties['configuration']['deploy']) {
            $this->logger->event('failure', self::ERR_NO_DEPLOY_SCRIPTS);
            return 301;
        }

        // SKIP pre-push commands
        if ($properties['configuration']['pre_push']) {
            $this->logger->event('info', self::SKIP_PRE_PUSH);
        }

        // // run deploy
        if (!$this->deploy($properties)) {
            return 302;
        }

        // SKIP post-push commands
        if ($properties['configuration']['post_push']) {
            $this->logger->event('info', self::SKIP_POST_PUSH);
        }

        // success
        return 0;
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function sanityCheck(array $properties)
    {
        if (!isset($properties[ServerEnum::TYPE_SCRIPT])) {
            return false;
        }

        if (!array_key_exists('deploy', $properties['configuration'])) {
            return false;
        }

        return true;
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function deploy(array $properties)
    {
        $this->status('Running deploy command', self::SECTION);

        $builder = $this->builder;

        return $builder(
            $this->getOutput(),
            $properties['configuration']['system'],
            $properties['configuration']['deploy'],
            $properties
        );
    }
}