<?php
/**
 * @copyright (c) 2018 Steve Kluck
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\JobConfiguration;

use Hal\Agent\Logger\EventLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

class ConfigurationReader
{
    const FOUND = 'Loaded .hal.yaml configuration';
    const ERR_INVALID_YAML = '.hal.yaml was invalid';
    const ERR_INVALID_KEY = '.hal.yaml configuration key "%s" is invalid';
    const ERR_INVALID_ENV = '.hal.yaml env var for "%s" is invalid';
    const ERR_TOO_MANY_COOKS = 'Too many commands specified for "%s". Must be less than 10.';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var callable
     */
    private $fileLoader;

    /**
     * @var array
     */
    private $fileLocations;

    /**
     * @param EventLogger $logger
     * @param Filesystem $filesystem
     * @param Parser $parser
     * @param array $fileLocations
     */
    public function __construct(
        EventLogger $logger,
        Filesystem $filesystem,
        Parser $parser,
        array $fileLocations
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->parser = $parser;

        $this->fileLocations = $fileLocations;
        $this->fileLoader = $this->getDefaultFileLoader();
    }

    /**
     * @param string $buildPath
     * @param array $defaultConfig
     *
     * @return array|null
     */
    public function __invoke(string $buildPath, array $defaultConfig)
    {
        $contents = $this->findConfiguration($buildPath);

        if (!$contents) {
            return $defaultConfig;
        }

        $context = ['file' => $contents];

        $yaml = $this->loadYAML($contents);
        if ($yaml === null) {
            $this->logger->event('failure', self::ERR_INVALID_YAML, $context);
            return null;
        }

        $config = $defaultConfig;

        $texts = [
            'platform',
            'image',
            'dist',
            'transform_dist',
        ];

        foreach ($texts as $textProperty) {
            if (false === ($value = $this->validateKey($yaml, $textProperty))) {
                return $this->failOnKey($textProperty, $context);
            } elseif ($value) {
                $config[$textProperty] = $value;
            }
        }

        // load env
        if (array_key_exists('env', $yaml) && $yaml['env']) {
            if (!is_array($yaml['env'])) {
                return $this->failOnKey('env', $context);
            }

            foreach ($yaml['env'] as $envName => $vars) {
                if (!is_string($envName) || !is_array($vars)) {
                    return $this->failOnKey('env', $context);
                }

                foreach ($vars as $name => $value) {
                    if (!is_scalar($value) || preg_match('/^[a-zA-Z_]+[a-zA-Z0-9_]*$/', $name) !== 1) {
                        $this->logger->event('failure', sprintf(self::ERR_INVALID_ENV, $envName), $context);
                        return null;
                    }

                    if (!isset($config['env'][$envName])) {
                        $config['env'][$envName] = [];
                    }

                    $config['env'][$envName][$name] = trim($value);
                }
            }
        }

        // load lists
        $parsed = [
            'build',                // build stage 1

            'build_transform',      // deploy stage 1
            'before_deploy',        // deploy stage 2

            'deploy',               // deploy stage 3 (script deployments only)

            'after_deploy',         // deploy stage 4,

            'rsync_exclude',
            'rsync_before',             // deploy stage 3 (rsync only)
            'rsync_after',            // deploy stage 5 (rsync, success only)
        ];

        foreach ($parsed as $p) {
            $config[$p] = $this->validateList($yaml, $p, $context);

            // If any of the lists are null, an error occured.
            if ($config[$p] === null) {
                return null;
            }
        }

        // fall back to default for build system
        $config['platform'] = $config['platform'] ?? 'default';
        $config['image'] = $config['image'] ?? 'default';

        $context['configuration'] = $config;
        $this->logger->event('success', self::FOUND, $context);

        return $config;
    }

    /**
     * @param array $yaml
     * @param string $key
     *
     * @return string|null|false
     *
     * - string (good!)
     * - false (bad! validation failed)
     * - null (value not found, skip)
     */
    private function validateKey(array $yaml, $key)
    {
        if (array_key_exists($key, $yaml) && $yaml[$key]) {
            if (!is_scalar($yaml[$key])) {
                return false;
            }

            return (string) trim($yaml[$key]);
        }

        return null;
    }

    /**
     * @param array $yaml
     * @param string $key
     * @param array $context
     *
     * @return array|null
     */
    private function validateList(array $yaml, $key, array $context)
    {
        if (!array_key_exists($key, $yaml)) {
            return [];
        }

        $commands = $yaml[$key];
        if (!is_array($commands)) {
            $commands = [$commands];
        }

        // # of commands must be <=10
        if (count($commands) > 10) {
            $this->logger->event('failure', sprintf(self::ERR_TOO_MANY_COOKS, $key), $context);
            return null;
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
                $sanitized[] = trim($command);
            }
        }

        return $sanitized;
    }

    /**
     * @param string $buildPath
     *
     * @return string
     */
    private function findConfiguration($buildPath)
    {
        $configFile = '';
        foreach ($this->fileLocations as $possibleFile) {
            $possibleFilePath = rtrim($buildPath, '/') . '/' . $possibleFile;

            if ($this->filesystem->exists($possibleFilePath)) {
                $configFile = $possibleFilePath;
                break;
            }
        }

        if (!$configFile) {
            return '';
        }

        $file = call_user_func($this->fileLoader, $configFile);
        return $file;
    }

    /**
     * @param string $fileContents
     *
     * @return array|null
     */
    private function loadYAML($fileContents)
    {
        try {
            $yaml = $this->parser->parse($fileContents);
        } catch (ParseException $e) {
            return null;
        }

        if (!is_array($yaml)) {
            return null;
        }

        return $yaml;
    }

    /**
     * @return callable
     */
    private function getDefaultFileLoader()
    {
        return 'file_get_contents';
    }

    /**
     * @param string $key
     * @param array $context
     *
     * @return null
     */
    private function failOnKey($key, array $context)
    {
        $this->logger->event('failure', sprintf(self::ERR_INVALID_KEY, $key), $context);
        return null;
    }
}
