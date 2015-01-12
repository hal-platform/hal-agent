<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

class ConfigurationReader
{
    const FOUND = 'Found .hal9000.yml configuration';
    const ERR_INVALID_YAML = '.hal9000.yml was invalid';
    const ERR_INVALID_KEY = '.hal9000.yml configuration key "%s" is invalid';

    /**
     * @type string
     */
    const FS_CONFIG_FILE = '.hal9000.yml';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type Filesystem
     */
    private $filesystem;

    /**
     * @type Parser
     */
    private $parser;

    /**
     * @param EventLogger $logger
     * @param Filesystem $filesystem
     * @param Parser $parser
     */
    public function __construct(EventLogger $logger, Filesystem $filesystem, Parser $parser)
    {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->parser = $parser;
    }

    /**
     * @param string $buildPath
     * @param array $config
     *
     * @return bool
     */
    public function __invoke($buildPath, array &$config)
    {
        $configFile = rtrim($buildPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::FS_CONFIG_FILE;

        if (!$this->filesystem->exists($configFile)) {
            return true;
        }

        $file = file_get_contents($configFile);
        $context = ['file' => $file];

        try {
            $yaml = $this->parser->parse($file);
        } catch (ParseException $e) {
            $this->logger->event('failure', self::ERR_INVALID_YAML);
            return false;
        }

        if (array_key_exists('environment', $yaml) && $yaml['environment']) {
            $config['environment'] = $yaml['environment'];
        }

        // load environment
        if (array_key_exists('environment', $yaml) && $yaml['environment']) {
            if (!is_scalar($yaml['environment'])) {
                $this->logger->event('failure', sprintf(self::ERR_INVALID_KEY, 'environment'), $context);
                return false;
            }

            $config['environment'] = (string) $yaml['environment'];
        }

        // load dist
        if (array_key_exists('dist', $yaml) && $yaml['dist']) {
            if (!is_scalar($yaml['dist'])) {
                $this->logger->event('failure', sprintf(self::ERR_INVALID_KEY, 'dist'), $context);
                return false;
            }

            $config['dist'] = (string) $yaml['dist'];
        }

        $config['build'] = $this->validateCommands($yaml, 'build', $context);
        $config['build_transform'] = $this->validateCommands($yaml, 'build_transform', $context);
        $config['pre_push'] = $this->validateCommands($yaml, 'pre_push', $context);
        $config['post_push'] = $this->validateCommands($yaml, 'post_push', $context);

        // If any of the commands are null, an error occured.
        if (
            $config['build'] === null ||
            $config['build_transform'] === null ||
            $config['pre_push'] === null ||
            $config['post_push'] === null
        ) {
            return false;
        }

        $context['configuration'] = $config;
        $this->logger->event('success', self::FOUND, $context);
        return true;
    }

    /**
     * @param array $yaml
     * @param string $key
     * @param array $context
     *
     * @return array|null
     */
    private function validateCommands(array $yaml, $key, array $context)
    {
        if (!array_key_exists($key, $yaml)) {
            return [];
        }

        $commands = $yaml[$key];
        if (!is_array($commands)) {
            $commands = [$commands];
        }

        $sanitized = [];
        foreach ($commands as $command) {
            if (is_scalar($command) || is_null($command)) {
                $command = (string) $command;
            } else {
                // blow the fuck up
                $this->logger->event('failure', sprintf(self::ERR_INVALID_KEY, $key), $context);
                return null;
            }

            if ($command) {
                $sanitized[] = $command;
            }
        }

        return $sanitized;
    }
}
