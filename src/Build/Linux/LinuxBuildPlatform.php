<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux;

use Hal\Agent\Build\Linux\Steps\Configurator;
use Hal\Agent\Build\Linux\Steps\Cleaner;
use Hal\Agent\Build\Linux\Steps\Exporter;
use Hal\Agent\Build\Linux\Steps\Importer;
use Hal\Agent\Build\Linux\Steps\Packer;
use Hal\Agent\Build\Linux\Steps\Unpacker;
use Hal\Agent\Build\BuildPlatformInterface;
use Hal\Agent\Build\PlatformTrait;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\Entity\JobType\Build;

class LinuxBuildPlatform implements BuildPlatformInterface
{
    use FormatterTrait;
    // Comes with EmergencyBuildHandlerTrait, EnvironmentVariablesTrait, IOAwareTrait
    use PlatformTrait;

    private const STEP_1_CONFIGURING = 'Linux Platform - Validating Linux configuration';
    private const STEP_2_EXPORTING = 'Linux Platform - Exporting files to build server';
    private const STEP_3_BUILDING = 'Linux Platform - Running build steps';
    private const STEP_4_IMPORTING = 'Linux Platform - Importing artifacts from build server';
    private const STEP_5_CLEANING = 'Cleaning up remote builder instance "%s"';

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
     * @var Cleaner
     */
    private $cleaner;

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
     * @param Cleaner $cleaner
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
        Cleaner $cleaner,

        $defaultDockerImage
    ) {
        $this->logger = $logger;

        $this->configurator = $configurator;
        $this->exporter = $exporter;
        $this->builder = $builder;
        $this->importer = $importer;
        $this->cleaner = $cleaner;

        $this->decrypter = $decrypter;

        $this->defaultDockerImage = $defaultDockerImage;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(array $config, array $properties): bool
    {
        $job = $properties['build'];

        $image = $config['image'] ?? $this->defaultDockerImage;
        $steps = $config['build'] ?? [];

        if (!$platformConfig = $this->configurator($job)) {
            $this->sendFailureEvent(self::ERR_CONFIGURATOR);
            return $this->bombout(false);
        }

        if (!$this->export($platformConfig, $properties['workspace_path'])) {
            $this->sendFailureEvent(self::ERR_EXPORT);
            return $this->bombout(false);
        }

        // decrypt
        $env = $this->decrypt($properties['encrypted'], $platformConfig['environment_variables'], $config);
        if ($env === null) {
            $this->sendFailureEvent(self::ERR_BAD_DECRYPT);
            return $this->bombout(false);
        }

        // run build
        if (!$this->build($job->id(), $image, $platformConfig, $steps, $env)) {
            return $this->bombout(false);
        }

        if (!$this->import($platformConfig, $properties['workspace_path'])) {
            $this->sendFailureEvent(self::ERR_IMPORT);
            return $this->bombout(false);
        }

        // success
        return $this->bombout(true);
    }

    /**
     * @param Build $build
     *
     * @return array|null
     */
    private function configurator(Build $build)
    {
        $this->getIO()->section(self::STEP_1_CONFIGURING);

        $platformConfig = ($this->configurator)($build);

        if (!$platformConfig) {
            return null;
        }

        $this->outputTable($this->getIO(), 'Platform configuration:', $platformConfig);

        return $platformConfig;
    }

    /**
     * @param array $platformConfig
     * @param string $workspacePath
     *
     * @return bool
     */
    private function export(array $platformConfig, $workspacePath)
    {
        $this->getIO()->section(self::STEP_2_EXPORTING);

        $buildPath = $workspacePath . '/build';
        $localFile = $workspacePath . '/build_export.tgz';

        $connection = $platformConfig['builder_connection'];
        $remoteFile = $platformConfig['remote_file'];

        $this->getIO()->listing([
            sprintf('Workspace: <info>%s</info>', $buildPath),
            sprintf('Local File: <info>%s</info>', $localFile),
            sprintf('Remote File: <info>%s</info>', $remoteFile)
        ]);

        $response = ($this->exporter)($buildPath, $localFile, $connection, $remoteFile);

        if ($response) {
            // Set emergency handler in case of super fatal
            $this->enableEmergencyHandler(function () use ($connection, $remoteFile) {
                $this->cleanupServer($connection, $remoteFile);
            });
        }

        return $response;
    }

    /**
     * @param array $encryptedConfig
     * @param array $platformEnv
     * @param array $config
     *
     * @return array|null
     */
    private function decrypt(array $encryptedConfig, array $platformEnv, array $config)
    {
        $decrypted = $this->decrypter->decryptProperties($encryptedConfig);
        if (count($decrypted) !== count($encryptedConfig)) {
            return null;
        }

        $env = $this->determineEnviroment($platformEnv, $decrypted, $config['env']);

        return $env;
    }

    /**
     * @param array $jobID
     * @param array $image
     * @param array $platformConfig
     * @param array $steps
     * @param array $env
     *
     * @return bool
     */
    private function build($jobID, $image, array $platformConfig, array $steps, array $env)
    {
        $this->getIO()->section(self::STEP_3_BUILDING);

        $connection = $platformConfig['builder_connection'];
        $remoteFile = $platformConfig['remote_file'];

        $this->builder->setIO($this->getIO());

        return ($this->builder)($jobID, $image, $connection, $remoteFile, $steps, $env);
    }

    /**
     * @param array $platformConfig
     * @param string $workspacePath
     *
     * @return bool
     */
    private function import(array $platformConfig, $workspacePath)
    {
        $this->getIO()->section(self::STEP_4_IMPORTING);

        $buildPath = $workspacePath . '/build';
        $localFile = $workspacePath . '/build_import.tgz';

        $connection = $platformConfig['builder_connection'];
        $remoteFile = $platformConfig['remote_file'];

        $this->getIO()->listing([
            sprintf('Workspace: <info>%s</info>', $buildPath),
            sprintf('Remote File: <info>%s</info>', $remoteFile),
            sprintf('Local File: <info>%s</info>', $localFile),
        ]);

        return ($this->importer)($buildPath, $localFile, $connection, $remoteFile);
    }

    /**
     * @param string $remoteConnection
     * @param string $remoteFile
     *
     * @return void
     */
    private function cleanupServer($remoteConnection, $remoteFile)
    {
        $this->getIO()->note(sprintf(self::STEP_5_CLEANING, $remoteConnection));

        ($this->cleaner)($remoteConnection, $remoteFile);
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
