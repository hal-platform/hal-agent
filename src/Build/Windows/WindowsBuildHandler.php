<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Build\Windows;

use QL\Hal\Agent\Build\BuildHandlerInterface;
use QL\Hal\Agent\Build\EmergencyBuildHandlerTrait;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Utility\EncryptedPropertyResolver;

class WindowsBuildHandler implements BuildHandlerInterface, OutputAwareInterface
{
    // Comes with OutputAwareTrait
    use EmergencyBuildHandlerTrait;

    const SECTION = 'Building - Windows';
    const STATUS = 'Building on windows';
    const SERVER_TYPE = 'windows';
    const ERR_INVALID_BUILD_SYSTEM = 'Windows build system is not configured';
    const ERR_BAD_DECRYPT = 'An error occured while decrypting encrypted properties';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Exporter
     */
    private $exporter;

    /**
     * @var Builder
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
     * @param EventLogger $logger
     * @param Exporter $exporter
     * @param Builder $builder
     * @param Importer $importer
     * @param Cleaner $cleaner
     * @param EncryptedPropertyResolver $decrypter
     */
    public function __construct(
        EventLogger $logger,
        Exporter $exporter,
        Builder $builder,
        Importer $importer,
        Cleaner $cleaner,
        EncryptedPropertyResolver $decrypter
    ) {
        $this->logger = $logger;

        $this->exporter = $exporter;
        $this->builder = $builder;
        $this->importer = $importer;
        $this->cleaner = $cleaner;
        $this->decrypter = $decrypter;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(array $commands, array $properties)
    {
        $this->status(self::STATUS, self::SECTION);

        // sanity check
        if (!$this->sanityCheck($properties)) {
            $this->logger->event('failure', self::ERR_INVALID_BUILD_SYSTEM);
            return 200;
        }

        if (!$this->export($properties)) {
            return $this->bombout(201);
        }

        // decrypt
        $decrypted = $this->decrypt($properties);
        if ($decrypted === null) {
            $this->logger->event('failure', self::ERR_BAD_DECRYPT);
            return $this->bombout(202);
        }

        // run build
        if (!$this->build($properties, $commands, $decrypted)) {
            return $this->bombout(203);
        }

        if (!$this->import($properties)) {
            return $this->bombout(204);
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
        $this->status('Validating windows configuration', self::SECTION);

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
        $this->status('Exporting files to build server', self::SECTION);

        $localPath = $properties['location']['path'];
        $user = $properties[self::SERVER_TYPE]['buildUser'];
        $server = $properties[self::SERVER_TYPE]['buildServer'];
        $path = $properties[self::SERVER_TYPE]['remotePath'];

        $exporter = $this->exporter;
        $response = $exporter($localPath, $user, $server, $path);

        if ($response) {
            // Set emergency handler in case of super fatal
            $this->enableEmergencyHandler($this->cleaner, 'Cleaning up remote windows build server', $user, $server, $path);
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

        $env = $properties[self::SERVER_TYPE]['environmentVariables'];
        $user = $properties[self::SERVER_TYPE]['buildUser'];
        $server = $properties[self::SERVER_TYPE]['buildServer'];
        $path = $properties[self::SERVER_TYPE]['remotePath'];

        // merge decrypted properties into env
        if ($decrypted) {
            $env = $this->decrypter->mergePropertiesIntoEnv($env, $decrypted);
        }

        $builder = $this->builder;
        return $builder($user, $server, $path, $commands, $env);
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
        $user = $properties[self::SERVER_TYPE]['buildUser'];
        $server = $properties[self::SERVER_TYPE]['buildServer'];
        $path = $properties[self::SERVER_TYPE]['remotePath'];

        $importer = $this->importer;
        return $importer($localPath, $user, $server, $path);
    }
}
