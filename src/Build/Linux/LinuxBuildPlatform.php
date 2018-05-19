<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux;

use Hal\Agent\Build\Linux\Steps\Configurator;
use Hal\Agent\Build\Linux\Steps\Exporter;
use Hal\Agent\Build\Linux\Steps\Importer;
use Hal\Agent\Build\Linux\Steps\Packer;
use Hal\Agent\Build\Linux\Steps\Unpacker;
use Hal\Agent\Build\PlatformTrait;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\JobExecution;
use Hal\Agent\JobPlatformInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Build;

class LinuxBuildPlatform implements JobPlatformInterface
{
    use FormatterTrait;
    // Comes with EmergencyBuildHandlerTrait, EnvironmentVariablesTrait, IOAwareTrait
    use PlatformTrait;

    private const STEP_1_CONFIGURING = 'Linux Platform - Validating Linux configuration';
    private const STEP_2_EXPORTING = 'Linux Platform - Exporting artifacts to stage';
    private const STEP_3_BUILDING = 'Linux Platform - Running build steps';
    private const STEP_4_IMPORTING = 'Linux Platform - Importing artifacts from stage';

    private const ERR_CONFIGURATOR = 'Linux build platform is not configured correctly';
    private const ERR_EXPORT = 'Failed to export build to build system';
    private const ERR_IMPORT = 'Failed to import build artifacts from build system';
    private const ERR_BAD_DECRYPT = 'An error occured while decrypting encrypted configuration';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var EncryptedPropertyResolver
     */
    private $decrypter;

    /**
     * @var Configurator
     */
    private $configurator;

    /**
     * @var Exporter
     */
    private $exporter;

    /**
     * @var BuilderInterface
     */
    private $builder;

    /**
     * @var Importer
     */
    private $importer;

    /**
     * @var string
     */
    private $defaultDockerImage;

    /**
     * @param EventLogger $logger
     * @param EncryptedPropertyResolver $decrypter
     *
     * @param Configurator $configurator
     * @param Exporter $exporter
     * @param BuilderInterface $builder
     * @param Importer $importer
     *
     * @param string $defaultDockerImage
     */
    public function __construct(
        EventLogger $logger,
        EncryptedPropertyResolver $decrypter,
        Configurator $configurator,
        Exporter $exporter,
        BuilderInterface $builder,
        Importer $importer,
        $defaultDockerImage
    ) {
        $this->logger = $logger;

        $this->configurator = $configurator;
        $this->exporter = $exporter;
        $this->builder = $builder;
        $this->importer = $importer;

        $this->decrypter = $decrypter;

        $this->defaultDockerImage = $defaultDockerImage;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(Job $job, JobExecution $execution, array $properties): bool
    {
        $image = $execution->parameter('image') ?? $this->defaultDockerImage;
        $steps = $execution->steps();

        $basePath = $properties['workspace_path'];
        $workspacePath = "${basePath}/workspace";

        $encryptedEnv = $properties['encrypted'];

        if (!$platformConfig = $this->configurator($job)) {
            $this->sendFailureEvent(self::ERR_CONFIGURATOR);
            return $this->bombout(false);
        }

        $platformEnv = $platformConfig['environment_variables'];
        $stageID = $platformConfig['stage_id'];
        $stagePath = "${basePath}/${stageID}";

        if (!$this->export($workspacePath, $stagePath)) {
            $this->sendFailureEvent(self::ERR_EXPORT);
            return $this->bombout(false);
        }

        // decrypt
        $env = $this->decrypt($encryptedEnv, $platformEnv, $execution->parameter('env'));
        if ($env === null) {
            $this->sendFailureEvent(self::ERR_BAD_DECRYPT);
            return $this->bombout(false);
        }

        // run build
        if (!$this->build($job->id(), $image, $stagePath, $steps, $env)) {
            return $this->bombout(false);
        }

        if (!$this->import($workspacePath, $stagePath)) {
            $this->sendFailureEvent(self::ERR_IMPORT);
            return $this->bombout(false);
        }

        // success
        return $this->bombout(true);
    }

    /**
     * @param Job $job
     *
     * @return array|null
     */
    private function configurator(Job $job)
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
     * @param string $workspacePath
     * @param string $stagePath
     *
     * @return bool
     */
    private function export($workspacePath, $stagePath)
    {
        $this->getIO()->section(self::STEP_2_EXPORTING);

        $this->getIO()->listing([
            sprintf('Workspace: <info>%s</info>', $workspacePath),
            sprintf('Stage Path: <info>%s</info>', $stagePath),
        ]);

        return ($this->exporter)($workspacePath, $stagePath);
    }

    /**
     * @param array $encryptedConfig
     * @param array $platformEnv
     * @param array $env
     *
     * @return array|null
     */
    private function decrypt(array $encryptedConfig, array $platformEnv, array $env)
    {
        $decrypted = $this->decrypter->decryptProperties($encryptedConfig);
        if (count($decrypted) !== count($encryptedConfig)) {
            return null;
        }

        $env = $this->determineEnvironment($platformEnv, $decrypted, $env);

        return $env;
    }

    /**
     * @param string $jobID
     * @param string $image
     *
     * @param string $stagePath
     * @param array $steps
     * @param array $env
     *
     * @return bool
     */
    private function build($jobID, $image, $stagePath, array $steps, array $env)
    {
        $this->getIO()->section(self::STEP_3_BUILDING);

        $this->builder->setIO($this->getIO());

        return ($this->builder)($jobID, $image, $stagePath, $steps, $env);
    }

    /**
     * @param string $workspacePath
     * @param string $stagePath
     *
     * @return bool
     */
    private function import($workspacePath, $stagePath)
    {
        $this->getIO()->section(self::STEP_4_IMPORTING);

        $this->getIO()->listing([
            sprintf('Workspace: <info>%s</info>', $workspacePath),
            sprintf('Stage Path: <info>%s</info>', $stagePath),
        ]);

        return ($this->importer)($workspacePath, $stagePath);
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
