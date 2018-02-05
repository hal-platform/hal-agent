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
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\Entity\JobType\Build;

class LinuxBuildPlatform implements BuildPlatformInterface
{
    // Comes with EmergencyBuildHandlerTrait, EnvironmentVariablesTrait, OutputAwareTrait
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
    public function __invoke(string $image, array $commands, array $properties): bool
    {
        if (!$config = $this->configurator($properties['build'])) {
            return $this->bombout(false);
        }

        if (!$this->export($config, $properties)) {
            return $this->bombout(false);
        }

        // decrypt
        $decrypted = $this->decrypt($properties['decrypted'] ?? []);
        if ($decrypted === null) {
            $this->logger->event('failure', self::ERR_BAD_DECRYPT);
            return $this->bombout(false);
        }

        // run build
        if (!$this->build($image, $config, $properties, $commands, $decrypted)) {
            return $this->bombout(false);
        }

        if (!$this->import($config, $properties)) {
            return $this->bombout(false);
        }

        // success
        return $this->bombout(true);
    }

    /**
     * @param Build $build
     *
     * @return array
     */
    private function configurator(Build $build)
    {
        $this->getIO()->section(self::SECTION . ' - Validating Linux configuration');

        $config = ($this->configurator)($build);

        $rows = [];
        foreach ($config as $p => $v) {
            $v = json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $rows[] = [$p, $v];
        }

        $this->getIO()->table(['Configuration', 'Value'], $rows);

        return $config;
    }

    /**
     * @param array $config
     * @param array $properties
     *
     * @return bool
     */
    private function export(array $config, array $properties)
    {
        $this->getIO()->section(self::SECTION . ' - Exporting files to build server');

        $buildPath = $properties['workspace_path'] . '/build';
        $localFile = $properties['workspace_path'] . '/build_export.tgz';

        $connection = $config['build_connection'];
        $remoteFile = $config['remote_file'];

        $this->getIO()->listing([
            sprintf('Workspace: <info>%s</info>', $buildPath),
            sprintf('Local File: <info>%s</info>', $localFile),
            sprintf('Remote File: <info>%s</info>', $remoteFile)
        ]);

        $response = ($this->exporter)($buildPath, $localFile, $connection, $remoteFile);

        if ($response) {
            // Set emergency handler in case of super fatal
            $this->enableEmergencyHandler($this->cleaner, 'Cleaning up remote unix build server', [$connection, $remoteFile]);
        }

        return $response;
    }

    /**
     * @param array $decryptedConfiguration
     *
     * @return array|null
     */
    private function decrypt(array $decryptedConfiguration)
    {
        $decrypted = $this->decrypter->decryptProperties($decryptedConfiguration);
        if (count($decrypted) !== count($decryptedConfiguration)) {
            return null;
        }

        return $decrypted;
    }

    /**
     * @param array $image
     * @param array $config
     * @param array $properties
     * @param array $commands
     * @param array $decrypted
     *
     * @return bool
     */
    private function build($image, array $config, array $properties, array $commands, array $decrypted)
    {
        $this->getIO()->section(self::SECTION . ' - Running build command');

        $dockerImage = $image ?: $this->defaultDockerImage;

        $this->getIO()->text('Commands:');
        $this->getIO()->listing($commands);

        return true;

        // $env = $config['environmentVariables'];
        // $user = $config['buildUser'];
        // $server = $config['buildServer'];
        // $file = $config['remoteFile'];

        // $env = $this->determineEnviroment(
        //     $config['environmentVariables'],
        //     $decrypted,
        //     $properties['configuration']['env']
        // );

        // $this->builder->setIO($this->getIO());

        // return ($this->builder)($dockerImage, $user, $server, $file, $commands, $env);
    }

    /**
     * @param array $config
     * @param array $properties
     *
     * @return bool
     */
    private function import(array $config, array $properties)
    {
        $this->getIO()->section(self::SECTION . ' - Importing files from build server');

        $buildPath = $properties['workspace_path'] . '/build';
        $localFile = $properties['workspace_path'] . '/build_import.tgz';

        $connection = $config['build_connection'];
        $remoteFile = $config['remote_file'];

        $this->getIO()->listing([
            sprintf('Workspace: <info>%s</info>', $buildPath),
            sprintf('Remote File: <info>%s</info>', $remoteFile),
            sprintf('Local File: <info>%s</info>', $localFile),
        ]);

        return ($this->importer)($buildPath, $localFile, $connection, $remoteFile);
    }
}
