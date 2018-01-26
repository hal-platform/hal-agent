<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Unix;

use Hal\Agent\Build\EmergencyBuildHandlerTrait;
use Hal\Agent\Build\PlatformInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\OutputAwareInterface;
use Hal\Agent\Utility\EncryptedPropertyResolver;

class UnixBuildHandler implements PlatformInterface
{
    // Comes with OutputAwareTrait
    use EmergencyBuildHandlerTrait;

    const SECTION = 'Building - Unix';
    const STATUS = 'Building on unix';

    const PLATFORM_TYPE = 'linux';

    const ERR_INVALID_BUILD_SYSTEM = 'Unix build system is not configured';
    const ERR_BAD_DECRYPT = 'An error occured while decrypting encrypted properties';

    const DOCKER_PREFIX = 'docker:';

    /**
     * @var EventLogger
     */
    private $logger;

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
     * @param Exporter $exporter
     * @param BuilderInterface $builder
     * @param Importer $importer
     * @param Cleaner $cleaner
     * @param EncryptedPropertyResolver $decrypter
     * @param string $defaultDockerImage
     */
    public function __construct(
        EventLogger $logger,
        Exporter $exporter,
        BuilderInterface $builder,
        Importer $importer,
        Cleaner $cleaner,
        EncryptedPropertyResolver $decrypter,
        $defaultDockerImage
    ) {
        $this->logger = $logger;

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
    public function __invoke(array $commands, array $properties)
    {
        $this->status(self::STATUS, self::SECTION);

        // sanity check
        if (!isset($properties[self::PLATFORM_TYPE]) || !$this->sanityCheck($properties)) {
            $this->logger->event('failure', self::ERR_INVALID_BUILD_SYSTEM);
            return 100;
        }

        if (!$this->export($properties)) {
            return $this->bombout(101);
        }

        // decrypt
        $decrypted = $this->decrypt($properties);
        if ($decrypted === null) {
            $this->logger->event('failure', self::ERR_BAD_DECRYPT);
            return $this->bombout(102);
        }

        // run build
        if (!$this->build($properties, $commands, $decrypted)) {
            return $this->bombout(103);
        }

        if (!$this->import($properties)) {
            return $this->bombout(104);
        }

        // success
        return $this->bombout(0);
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function sanityCheck(array $properties)
    {
        $this->status('Validating unix configuration', self::SECTION);

        if (!isset($properties[self::PLATFORM_TYPE])) {
            return false;
        }

        if (!$properties[self::PLATFORM_TYPE]['buildUser'] || !$properties[self::PLATFORM_TYPE]['buildServer']) {
            return false;
        }

        return true;
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function export(array $properties)
    {
        $this->status('Exporting files to build server', self::SECTION);

        $localPath = $properties['location']['path'];

        // We override the local tempArchive used for the build/source download since its already been used.
        $localFile = $properties['location']['tempArchive'];

        $user = $properties[self::PLATFORM_TYPE]['buildUser'];
        $server = $properties[self::PLATFORM_TYPE]['buildServer'];
        $file = $properties[self::PLATFORM_TYPE]['remoteFile'];

        $exporter = $this->exporter;
        $response = $exporter($localPath, $localFile, $user, $server, $file);

        if ($response) {
            // Set emergency handler in case of super fatal
            $this->enableEmergencyHandler($this->cleaner, 'Cleaning up remote unix build server', [$user, $server, $file]);
        }

        return $response;
    }

    /**
     * @param array $properties
     *
     * @return array|null
     */
    private function decrypt(array $properties)
    {
        if (!isset($properties['encrypted'])) {
            return [];
        }

        $decrypted = $this->decrypter->decryptProperties($properties['encrypted']);
        if (count($decrypted) !== count($properties['encrypted'])) {
            return null;
        }

        return $decrypted;
    }

    /**
     * @param array $properties
     * @param array $commands
     * @param array $decrypted
     *
     * @return boolean
     */
    private function build(array $properties, array $commands, array $decrypted)
    {
        $this->status('Running build command', self::SECTION);

        $dockerImage = $properties['configuration']['image'] ? $properties['configuration']['image'] : $this->defaultDockerImage;

        $env = $properties[self::PLATFORM_TYPE]['environmentVariables'];
        $user = $properties[self::PLATFORM_TYPE]['buildUser'];
        $server = $properties[self::PLATFORM_TYPE]['buildServer'];
        $file = $properties[self::PLATFORM_TYPE]['remoteFile'];

        // decrypt and add encrypted properties to env if possible
        if ($decrypted) {
            $env = $this->decrypter->mergePropertiesIntoEnv($env, $decrypted);
        }

        $env = $this->mergeUserProvidedEnv($env, $properties['configuration']['env']);

        $builder = $this->builder;

        if ($builder instanceof OutputAwareInterface && $this->getOutput()) {
            $builder->setOutput($this->getOutput());
        }

        return $builder($dockerImage, $user, $server, $file, $commands, $env);
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function import(array $properties)
    {
        $this->status('Importing files from build server', self::SECTION);

        $localPath = $properties['location']['path'];
        $localFile = $properties['location']['tempArchive'];

        $user = $properties[self::PLATFORM_TYPE]['buildUser'];
        $server = $properties[self::PLATFORM_TYPE]['buildServer'];
        $file = $properties[self::PLATFORM_TYPE]['remoteFile'];

        $importer = $this->importer;
        return $importer($localPath, $localFile, $user, $server, $file);
    }

    /**
     * - env overrides global
     * - global overrides encrypted or hal-specified config
     *
     * @param array $env
     * @param array $configurationEnv
     *
     * @return array
     */
    private function mergeUserProvidedEnv(array $env, array $configurationEnv)
    {
        $localEnv = [];
        if (isset($configurationEnv['global'])) {
            foreach ($configurationEnv['global'] as $name => $value) {
                $localEnv[$name] = $value;
            }
        }
        $targetEnv = isset($env['HAL_ENVIRONMENT']) ? $env['HAL_ENVIRONMENT'] : '';
        if (isset($configurationEnv[$targetEnv])) {
            foreach ($configurationEnv[$targetEnv] as $name => $value) {
                $localEnv[$name] = $value;
            }
        }
        return $env + $localEnv;
    }
}
