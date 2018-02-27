<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3;

use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Deploy\AWS\Configurator as AWSConfigurator;
use Hal\Agent\Deploy\S3\Steps\Configurator as S3Configurator;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Build\PlatformTrait;
use Hal\Agent\JobPlatformInterface;
use Hal\Agent\JobExecution;
use Hal\Agent\Symfony\IOAwareInterface;
use Hal\Agent\Symfony\IOAwareTrait;
use Hal\Core\Entity\Job;
use Hal\Core\Type\TargetEnum;
use Symfony\Component\DependencyInjection\ContainerInterface;

class S3DeployPlatform implements IOAwareInterface, JobPlatformInterface
{
    use FormatterTrait;
    // Comes with EmergencyBuildHandlerTrait, EnvironmentVariablesTrait, IOAwareTrait
    use PlatformTrait;

    private const STEP_1_CONFIGURING = 'S3 Platform - Validating S3 configuration';
    private const STEP_2_DELEGATING = 'S3 Platform - Determining S3 subplatform';

    private const ERR_CONFIGURATOR = 'S3 deploy platform is not configured correctly';
    private const ERR_DELEGATOR = 'S3 deploy platform is not configured correctly';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var AWSConfigurator
     */
    private $awsConfigurator;

    /**
     * @var S3Configurator
     */
    private $s3Configurator;

    /**
     ** An associative array of s3 sub platforms by strategy
     * Example:
     *     [
     *         artifact => 'deploy_platform.s3.artifact',
     *         sync => 'deploy_platform.s3.sync'
     *     ]
     *
     * @var array
     */
    private $subPlatforms;

    /**
     * @param EventLogger $logger
     *
     * @param AWSConfigurator $awsConfigurator
     * @param S3Configurator $s3Configurator
     *
     * @param ContainerInterface $container
     * @param array $subPlatforms
     */
    public function __construct(
        EventLogger $logger,
        AWSConfigurator $awsConfigurator,
        S3Configurator $s3Configurator,
        ContainerInterface $container,
        array $subPlatforms = []
    ) {
        $this->logger = $logger;

        $this->awsConfigurator = $awsConfigurator;
        $this->s3Configurator = $s3Configurator;

        $this->container = $container;
        $this->subPlatforms = $subPlatforms;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(Job $job, JobExecution $execution, array $properties): bool
    {
        $steps = $execution->steps();

        if (!$config = $this->configurator($job)) {
            $this->sendFailureEvent(self::ERR_CONFIGURATOR);
            return $this->bombout(false);
        }

        if (!$subPlatform = $this->delegator($config)) {
            $this->sendFailureEvent(self::ERR_DELEGATOR);
            return $this->bombout(false);
        }

        return $subPlatform($job, $execution, $properties, $config);
    }

    /**
     * @param Job $job
     *
     * @return array|null
     */
    private function configurator(Job $job)
    {
        $this->getIO()->section(self::STEP_1_CONFIGURING);

        $awsConfig = ($this->awsConfigurator)($job);
        if (!$awsConfig) {
            return null;
        }

        $s3Config = ($this->s3Configurator)($job);
        if (!$s3Config) {
            return null;
        }

        $platformConfig = array_merge($awsConfig, $s3Config);

        $this->outputTable($this->getIO(), 'Platform configuration:', $platformConfig);

        return $platformConfig;
    }

    /**
     * @param array $properties
     *
     * @return JobPlatformInterface|null
     */
    private function delegator(array $properties)
    {
        $this->getIO()->section(self::STEP_2_DELEGATING);

        if (!isset($properties[TargetEnum::TYPE_S3]['strategy'])) {
            return null;
        }

        $strategy = $properties[TargetEnum::TYPE_S3]['strategy'];
        $dependency = $this->subPlatforms[$strategy];
        $subPlatform = $this->container->get($dependency, ContainerInterface::NULL_ON_INVALID_REFERENCE);

        if (!$subPlatform instanceof S3DeployInterface) {
            return null;
        }

        if ($subPlatform instanceof IOAwareInterface) {
            $subPlatform->setIO($this->getIO());
        }

        return $subPlatform;
    }

    /**
     * @param string $message
     *
     * @return void
     */
    private function sendFailureEvent($message)
    {
        $this->logger->event('failure', $message);
        $this->getIO()->error($message);
    }
}
