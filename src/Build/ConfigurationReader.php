<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Build\Unix\DockerBuilder as LinuxDockerBuilder;
use Hal\Agent\Build\Unix\UnixBuildHandler;
use Hal\Agent\Build\WindowsAWS\DockerBuilder as WindowsDockerBuilder;
use Hal\Agent\Build\WindowsAWS\WindowsAWSBuildHandler;
use Hal\Agent\Logger\EventLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

class ConfigurationReader
{
    const FOUND = 'Found .hal9000.yml configuration';
    const ERR_INVALID_YAML = '.hal9000.yml was invalid';
    const ERR_INVALID_KEY = '.hal9000.yml configuration key "%s" is invalid';
    const ERR_INVALID_ENV = '.hal9000.yml env var for "%s" is invalid';
    const ERR_TOO_MANY_COOKS = 'Too many commands specified for "%s". Must be less than 10.';

    /**
     * @var string
     */
    const FS_CONFIG_FILE = '.hal9000.yml';

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
     * @param EventLogger $logger
     * @param Filesystem $filesystem
     * @param Parser $parser
     * @param callable $fileLoader
     */
    public function __construct(
        EventLogger $logger,
        Filesystem $filesystem,
        Parser $parser,
        callable $fileLoader = null
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->parser = $parser;

        if ($fileLoader === null) {
            $fileLoader = $this->getDefaultFileLoader();
        }

        $this->fileLoader = $fileLoader;
    }

    /**
     * @param string $buildPath
     * @param array $config
     *
     * @return array|null
     */
    public function __invoke($buildPath, array $config)
    {
        $configFile = rtrim($buildPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::FS_CONFIG_FILE;

        if (!$this->filesystem->exists($configFile)) {
            return $config;
        }

        $file = call_user_func($this->fileLoader, $configFile);
        $context = ['file' => $file];

        try {
            $yaml = $this->parser->parse($file);
        } catch (ParseException $e) {
            $this->logger->event('failure', self::ERR_INVALID_YAML);
            return null;
        }

        if (!is_array($yaml)) {
            $this->logger->event('failure', self::ERR_INVALID_YAML);
            return null;
        }

        // load platform/image, preferred
        if (false === ($value = $this->validateKey($yaml, 'platform', $context))) {
            return null;
        } elseif ($value) {
            $config['platform'] = $value;
        }

        if (false === ($value = $this->validateKey($yaml, 'image', $context))) {
            return null;
        } elseif ($value) {
            $config['image'] = $value;
        }

        // load dist
        if (false === ($value = $this->validateKey($yaml, 'dist', $context))) {
            return null;
        } elseif ($value) {
            $config['dist'] = $value;
        }

        // load transform_dist
        if (false === ($value = $this->validateKey($yaml, 'transform_dist', $context))) {
            return null;
        } elseif ($value) {
            $config['transform_dist'] = $value;
        }

        // load env
        if (array_key_exists('env', $yaml) && $yaml['env']) {
            if (!is_array($yaml['env'])) {
                $this->logger->event('failure', sprintf(self::ERR_INVALID_KEY, 'env'), $context);
                return null;
            }

            foreach ($yaml['env'] as $envName => $vars) {
                if (!is_string($envName) || !is_array($vars)) {
                    $this->logger->event('failure', sprintf(self::ERR_INVALID_KEY, 'env'), $context);
                    return null;
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
                'exclude',

                'build',                // build stage, 1

                'build_transform',      // deploy stage 1
                'before_deploy',        // deploy stage 2

                'pre_push',             // deploy stage 3 (rsync only)
                'deploy',               // deploy stage 4 (script deployments only)
                'post_push',            // deploy stage 5 (rsync, success only)

                'after_deploy'          // deploy stage 6
            ];
        foreach ($parsed as $p) {
            $config[$p] = $this->validateList($yaml, $p, $context);

            // If any of the lists are null, an error occured.
            if ($config[$p] === null) {
                return null;
            }
        }

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
    private function validateKey(array $yaml, $key, array $context)
    {
        if (array_key_exists($key, $yaml) && $yaml[$key]) {
            if (!is_scalar($yaml[$key])) {
                $this->logger->event('failure', sprintf(self::ERR_INVALID_KEY, $key), $context);
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
     * @return string
     */
    private function getDefaultFileLoader()
    {
        return 'file_get_contents';
    }
}
