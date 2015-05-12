<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Unix;

use QL\Hal\Agent\Build\BuildHandlerInterface;
use QL\Hal\Agent\Build\EmergencyBuildHandlerTrait;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Utility\EncryptedPropertyResolver;

class UnixBuildHandler implements BuildHandlerInterface, OutputAwareInterface
{
    use EmergencyBuildHandlerTrait;

    const STATUS = 'Building on unix';
    const SERVER_TYPE = 'unix';
    const ERR_INVALID_BUILD_SYSTEM = 'Unix build system is not configured';
    const ERR_BAD_DECRYPT = 'An error occured while decrypting encrypted properties';
    const DOCKER_PREFIX = 'docker:';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type Exporter
     */
    private $exporter;

    /**
     * @type BuilderInterface
     */
    private $builder;

    /**
     * @type Importer
     */
    private $importer;

    /**
     * @type Cleaner
     */
    private $cleaner;

    /**
     * @type EncryptedPropertyResolver
     */
    private $decrypter;

    /**
     * @type string
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
        $this->status(self::STATUS);

        // sanity check
        if (!$this->sanityCheck($properties)) {
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
        $this->status('Validating unix configuration');

        if (!isset($properties[self::SERVER_TYPE])) {
            return false;
        }

        if (!$properties[self::SERVER_TYPE]['buildUser'] || !$properties[self::SERVER_TYPE]['buildServer']) {
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
        $this->status('Exporting files to build server');

        $localPath = $properties['location']['path'];
        $user = $properties[self::SERVER_TYPE]['buildUser'];
        $server = $properties[self::SERVER_TYPE]['buildServer'];
        $path = $properties[self::SERVER_TYPE]['remotePath'];

        $exporter = $this->exporter;
        $response = $exporter($localPath, $user, $server, $path);

        if ($response) {
            // Set emergency handler in case of super fatal
            $this->enableEmergencyHandler($this->cleaner, 'Cleaning up remote unix build server', $user, $server, $path);
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
        $this->status('Running build command');

        $dockerImage = $this->determineImage($properties['configuration']['system']);

        $env = $properties[self::SERVER_TYPE]['environmentVariables'];
        $user = $properties[self::SERVER_TYPE]['buildUser'];
        $server = $properties[self::SERVER_TYPE]['buildServer'];
        $path = $properties[self::SERVER_TYPE]['remotePath'];

        // decrypt and add encrypted properties to env if possible
        if ($decrypted) {
            $env = $this->decrypter->mergePropertiesIntoEnv($env, $decrypted);
        }

        $builder = $this->builder;

        if ($builder instanceof OutputAwareInterface && $this->getOutput()) {
            $builder->setOutput($this->getOutput());
        }

        return $builder($dockerImage, $user, $server, $path, $commands, $env);
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function import(array $properties)
    {
        $this->status('Importing files from build server');

        $localPath = $properties['location']['path'];
        $user = $properties[self::SERVER_TYPE]['buildUser'];
        $server = $properties[self::SERVER_TYPE]['buildServer'];
        $path = $properties[self::SERVER_TYPE]['remotePath'];

        $importer = $this->importer;
        return $importer($localPath, $user, $server, $path);
    }

    /**
     * @param system $system
     *
     * @return string
     */
    private function determineImage($system)
    {
        // "unix" = default
        if ($system === self::SERVER_TYPE) {
            return $this->defaultDockerImage;
        }

        // remove "docker:" prefix
        if (substr($system, 0, 7) === self::DOCKER_PREFIX) {
            $system = substr($system, 7);
        }

        // malformed = default
        if (!$system) {
            return $this->defaultDockerImage;
        }

        return $system;
    }
}
