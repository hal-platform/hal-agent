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

    const SECTION = 'Linux Platform';
    const ERR_BAD_DECRYPT = 'An error occured while decrypting encrypted configuration';

    /**
     * @var EventLogger
     */
    private $logger;

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
     * @var EncryptedPropertyResolver
     */
    private $decrypter;

    /**
     * @var string
     */
    private $defaultDockerImage;

    /**
     * @param EventLogger $logger
     *
     * @param Configurator $configurator
     * @param Exporter $exporter
     * @param BuilderInterface $builder
     * @param Importer $importer
     * @param Cleaner $cleaner
     * @param EncryptedPropertyResolver $decrypter
     * @param string $defaultDockerImage
     */
    public function __construct(
        EventLogger $logger,
        Configurator $configurator,
        Exporter $exporter,
        BuilderInterface $builder,
        Importer $importer,
        Cleaner $cleaner,
        EncryptedPropertyResolver $decrypter,
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
        $commands = $config['build'];

        if (!$platformConfig = $this->configurator($job)) {
            return $this->bombout(false);
        }

        if (!$this->export($platformConfig, $properties)) {
            return $this->bombout(false);
        }

        // decrypt
        $env = $this->decrypt($properties['encrypted'], $platformConfig, $config);
        if ($env === null) {
            $this->logger->event('failure', self::ERR_BAD_DECRYPT);
            return $this->bombout(false);
        }

        // run build
        if (!$this->build($job->id(), $image, $platformConfig, $commands, $env)) {
            return $this->bombout(false);
        }

        if (!$this->import($platformConfig, $properties)) {
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
        $this->getIO()->section(self::SECTION . ' - Validating Linux configuration');

        $platformConfig = ($this->configurator)($build);

        if (!$platformConfig) {
            return null;
        }

        $this->outputTable($this->getIO(), 'Platform configuration:', $platformConfig);

        return $platformConfig;
    }

    /**
     * @param array $platformConfig
     * @param array $properties
     *
     * @return bool
     */
    private function export(array $platformConfig, array $properties)
    {
        $this->getIO()->section(self::SECTION . ' - Exporting files to build server');

        $buildPath = $properties['workspace_path'] . '/build';
        $localFile = $properties['workspace_path'] . '/build_export.tgz';

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
     * @param array $platformConfig
     * @param array $config
     *
     * @return array|null
     */
    private function decrypt(array $encryptedConfig, array $platformConfig, array $config)
    {
        $decrypted = $this->decrypter->decryptProperties($encryptedConfig);
        if (count($decrypted) !== count($encryptedConfig)) {
            return null;
        }

        $env = $this->determineEnviroment($platformConfig['environment_variables'], $decrypted, $config['env']);

        return $env;
    }

    /**
     * @param array $jobID
     * @param array $image
     * @param array $platformConfig
     * @param array $commands
     * @param array $env
     *
     * @return bool
     */
    private function build($jobID, $image, array $platformConfig, array $commands, array $env)
    {
        $this->getIO()->section(self::SECTION . ' - Running build steps');

        $connection = $platformConfig['builder_connection'];
        $remoteFile = $platformConfig['remote_file'];

        $this->builder->setIO($this->getIO());

        return ($this->builder)($jobID, $image, $connection, $remoteFile, $commands, $env);
    }

    /**
     * @param array $platformConfig
     * @param array $properties
     *
     * @return bool
     */
    private function import(array $platformConfig, array $properties)
    {
        $this->getIO()->section(self::SECTION . ' - Importing files from build server');

        $buildPath = $properties['workspace_path'] . '/build';
        $localFile = $properties['workspace_path'] . '/build_import.tgz';

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
        $this->getIO()->note(sprintf('Cleaning up remote build server "%s"', $remoteConnection));

        ($this->cleaner)($remoteConnection, $remoteFile);
    }
}
