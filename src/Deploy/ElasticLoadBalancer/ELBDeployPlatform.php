<?php
/**
 * @copyright (c) 2018 Steve Kluck
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticLoadBalancer;

use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Deploy\ElasticLoadBalancer\Steps\Configurator;
use Hal\Agent\Deploy\ElasticLoadBalancer\Steps\EC2Finder;
use Hal\Agent\Deploy\ElasticLoadBalancer\Steps\ELBManager;
use Hal\Agent\Deploy\ElasticLoadBalancer\Steps\HealthChecker;
use Hal\Agent\Deploy\ElasticLoadBalancer\Steps\Swapper;
use Hal\Agent\Deploy\PlatformTrait;
use Hal\Agent\JobExecution;
use Hal\Agent\JobPlatformInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\IOAwareInterface;
use Hal\Agent\Symfony\IOAwareTrait;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Release;

class ELBDeployPlatform implements IOAwareInterface, JobPlatformInterface
{
    use FormatterTrait;
    // Comes with IOAwareTrait
    use PlatformTrait;

    private const STEP_1_CONFIGURING = 'ELB Platform - Validating ELB configuration';
    private const STEP_2_FETCH_EC2_INSTANCES = 'ELB Platform - Fetch tagged EC2 Instances';
    private const STEP_3_FETCH_ACTIVE_INSTANCES = 'ELB Platform - Fetch and validate instances from Active ELB';
    private const STEP_4_FETCH_PASSIVE_INSTANCES = 'ELB Platform - Fetch and validate instances from Passive ELB';
    private const STEP_5_SWAP_PASSIVE_INSTANCES = 'ELB Platform - Swap instances in Passive ELB';
    private const STEP_6_SWAP_ACTIVE_INSTANCES = 'ELB Platform - Swap instances in Active ELB';
    private const STEP_7_ACTIVE_HEALTH = 'ELB Platform - Check health state of Active ELB';
    private const STEP_8_PASSIVE_HEALTH = 'ELB Platform - Check health state of Passive ELB';

    private const ERR_CONFIGURATOR = 'Elastic Load Balancer deploy platform is not configured correctly';
    private const ERR_INVALID_JOB = 'The provided job is an invalid type for this job platform';
    private const ERR_TAGGED_INSTANCES = 'Could not find tagged instances';
    private const ERR_PASSIVE_INSTANCES = 'Could not find valid instances in Passive ELB';
    private const ERR_ACTIVE_INSTANCES = 'Could not find valid instances in Active ELB';
    private const ERR_SWAP_ACTIVE_INSTANCES = 'Could not swap instances in Active ELB';
    private const ERR_SWAP_PASSIVE_INSTANCES = 'Could not swap instances in Passive ELB';
    private const ERR_ACTIVE_HEALTH = 'Active Elastic Load Balancer is not ready';
    private const ERR_PASSIVE_HEALTH = 'Passive Elastic Load Balancer is not ready';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Configurator
     */
    private $configurator;

    /**
     * @var HealthChecker
     */
    private $health;

    /**
     * @var EC2Finder
     */
    private $ec2Finder;

    /**
     * @var Swapper
     */
    private $swapper;

    /**
     * @var ELBManager
     */
    private $elbManager;

    /**
     * @param EventLogger $logger
     * @param Configurator $configurator
     * @param HealthChecker $health
     * @param EC2Finder $ec2Finder
     * @param Swapper $swapper
     * @param ELBManager $elbManager
     */
    public function __construct(
        EventLogger $logger,
        configurator $configurator,
        HealthChecker $health,
        EC2Finder $ec2Finder,
        Swapper $swapper,
        ELBManager $elbManager
    ) {
        $this->logger = $logger;
        $this->configurator= $configurator;
        $this->health = $health;
        $this->ec2Finder = $ec2Finder;
        $this->swapper = $swapper;
        $this->elbManager = $elbManager;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(Job $job, JobExecution $execution, array $properties): bool
    {
        if (!$job instanceof Release) {
            $this->sendFailureEvent(self::ERR_INVALID_JOB);
            return false;
        }

        if (!$platformConfig = $this->configurator($job)) {
            $this->sendFailureEvent(self::ERR_CONFIGURATOR);
            return false;
        }

        if (!$taggedInstances = $this->fetchTaggedInstances($platformConfig)) {
            $this->sendFailureEvent(self::ERR_TAGGED_INSTANCES);
            return false;
        }

        if (!$activeInstances = $this->fetchActiveELBInstances($platformConfig, $taggedInstances)) {
            $this->sendFailureEvent(self::ERR_ACTIVE_INSTANCES);
            return false;
        }

        if (!$passiveInstances = $this->fetchPassiveELBInstances($platformConfig, $taggedInstances)) {
            $this->sendFailureEvent(self::ERR_PASSIVE_INSTANCES);
            return false;
        }

        if (!$this->swapPassiveELBInstances($platformConfig, $activeInstances, $passiveInstances)) {
            $this->sendFailureEvent(self::ERR_SWAP_PASSIVE_INSTANCES);
            return false;
        }

        if (!$this->swapActiveELBInstances($platformConfig, $passiveInstances, $activeInstances)) {
            $this->sendFailureEvent(self::ERR_SWAP_ACTIVE_INSTANCES);
            return false;
        }

        if (!$this->checkActiveELBHealth($platformConfig)) {
            $this->sendFailureEvent(self::ERR_ACTIVE_HEALTH);
            return false;
        }

        if (!$this->checkPassiveELBHealth($platformConfig)) {
            $this->sendFailureEvent(self::ERR_PASSIVE_HEALTH);
            return false;
        }

        return true;
    }

    /**
     * @param Release $job
     *
     * @return ?array
     */
    private function configurator(Release $job)
    {
        $this->getIO()->section(self::STEP_1_CONFIGURING);

        $platformConfig = ($this->configurator)($job);

        if (!$platformConfig) {
            return null;
        }

        $this->outputTable($this->getIO(), 'Platform configuration:', $platformConfig);

        return $platformConfig;
    }

    /**
     * Get all tagged instances. These will be cross-referenced with those in the ELBs.
     *
     * @param array $platformConfig
     *
     * @return ?array
     */
    private function fetchTaggedInstances(array $platformConfig)
    {
        $this->getIO()->section(self::STEP_2_FETCH_EC2_INSTANCES);

        $ec2 = $platformConfig['sdk']['ec2'];
        $elbTag = $platformConfig['ec2_tag'];

        $tagged = ($this->ec2Finder)($ec2, $elbTag);

        if ($tagged) {
            return $tagged;
        }

        return null;
    }

    /**
     * @param array $platformConfig
     * @param array $taggedInstances
     *
     * @return ?array
     */
    private function fetchActiveELBInstances(array $platformConfig, array $taggedInstances)
    {
        $this->getIO()->section(self::STEP_3_FETCH_ACTIVE_INSTANCES);

        return $this->fetchELBInstances($platformConfig, 'active_lb', $taggedInstances);
    }

    /**
     * @param array $platformConfig
     * @param array $taggedInstances
     *
     * @return ?array
     */
    private function fetchPassiveELBInstances(array $platformConfig, array $taggedInstances)
    {
        $this->getIO()->section(self::STEP_4_FETCH_PASSIVE_INSTANCES);

        return $this->fetchELBInstances($platformConfig, 'passive_lb', $taggedInstances);
    }

    /**
     * @param array $platformConfig
     * @param string $elbNameConfigIndex
     *
     * @return ?array
     */
    private function fetchELBInstances(array $platformConfig, string $elbNameConfigIndex, array $taggedInstances)
    {
        $elb = $platformConfig['sdk']['elb'];
        $elbName = $platformConfig[$elbNameConfigIndex];

        $instances = ($this->elbManager)->getValidELBInstances($elb, $elbName, $taggedInstances);

        if (!$instances) {
            return null;
        }

        return $instances;
    }

    /**
     * @param array $platformConfig
     * @param array $passiveELBInstances
     * @param array $activeELBInstances
     *
     * @return bool
     */
    private function swapPassiveELBInstances(array $platformConfig, array $passiveELBInstances, array $activeELBInstances)
    {
        $this->getIO()->section(self::STEP_5_SWAP_PASSIVE_INSTANCES);

        return $this->swapELBInstances('passive_lb', $platformConfig, $passiveELBInstances, $activeELBInstances);
    }

    /**
     * @param array $platformConfig
     * @param array $activeELBInstances
     * @param array $passiveELBInstances
     *
     * @return bool
     */
    private function swapActiveELBInstances(array $platformConfig, array $activeELBInstances, array $passiveELBInstances)
    {
        $this->getIO()->section(self::STEP_6_SWAP_ACTIVE_INSTANCES);

        return $this->swapELBInstances('active_lb', $platformConfig, $activeELBInstances, $passiveELBInstances);
    }

    /**
     * @param string $lbProp
     * @param array $platformConfig
     * @param array $toRegisterInstances
     * @param array $toDeregisterInstances
     *
     * @return bool
     */
    private function swapELBInstances(string $lbProp, array $platformConfig, array $toRegisterInstances, array $toDeregisterInstances)
    {
        $elb = $platformConfig['sdk']['elb'];
        $activeELBName = $platformConfig[$lbProp];

        return ($this->swapper)($elb, $activeELBName, $toRegisterInstances, $toDeregisterInstances);
    }

    /**
     * @param array $platformConfig
     *
     * @return bool
     */
    private function checkActiveELBHealth(array $platformConfig)
    {
        $this->getIO()->section(self::STEP_7_ACTIVE_HEALTH);

        $elb = $platformConfig['sdk']['elb'];

        $elbName = $platformConfig['active_lb'];

        return ($this->health)($elb, $elbName);
    }

    /**
     * @param array $platformConfig
     *
     * @return bool
     */
    private function checkPassiveELBHealth(array $platformConfig)
    {
        $this->getIO()->section(self::STEP_8_PASSIVE_HEALTH);

        $elb = $platformConfig['sdk']['elb'];

        $elbName = $platformConfig['passive_lb'];

        return ($this->health)($elb, $elbName);
    }

    /**
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    private function sendFailureEvent($message, $context = [])
    {
        $this->logger->event('failure', $message, $context);
        $this->getIO()->error($message);
    }
}
