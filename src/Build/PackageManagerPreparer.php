<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class PackageManagerPreparer
{
    /**
     * @var string
     */
    const NPMRC_FS = '%s/.npmrc';
    const COMPOSER_CONFIG_FS = '%s/config.json';

    /**
     * $COMPOSER_HOME/config.json
     *
     * @var string
     */
    const COMPOSER_CONFIG_DEFAULT = <<<'JSON'
{
    "config": {
        "github-oauth" : {
            "github.com": "%s"
        }
    }
}

JSON;

    /**
     * $HOME/.npmrc
     *
     * @var string
     */
    const NPMRC_DEFAULT = <<<'INI'
strict-ssl = false

INI;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var string
     */
    private $githubAuthToken;

    /**
     * @param LoggerInterface $logger
     * @param Filesystem $filesystem
     * @param string $githubAuthToken
     */
    public function __construct(LoggerInterface $logger, Filesystem $filesystem, $githubAuthToken)
    {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->githubAuthToken = $githubAuthToken;
    }

    /**
     * @param array $environment
     * @return null
     */
    public function __invoke(array $environment)
    {
        $npmrc = sprintf(self::NPMRC_FS, $environment['HOME']);
        $composerConfig = sprintf(self::COMPOSER_CONFIG_FS, $environment['COMPOSER_HOME']);

        $this->handleComposerConfiguration($composerConfig);
        $this->handleNPMConfiguration($npmrc);
    }

    /**
     * @param string $filename
     * @return null
     */
    private function handleComposerConfiguration($filename)
    {
        $context = ['composer-config' => $filename];

        if ($this->filesystem->exists($filename)) {
            $this->logger->info('Composer configuration found.', $context);
            return;
        }

        $config = sprintf(self::COMPOSER_CONFIG_DEFAULT, $this->githubAuthToken);
        if ($this->write($filename, $config)) {
            $this->logger->info('Composer configuration written successfully.', $context);
            return;
        }

        $this->logger->warning('Composer configuration could not be written.', $context);
    }

    /**
     * @param string $filename
     * @return null
     */
    private function handleNPMConfiguration($filename)
    {
        $context = ['npm-config' => $filename];

        if ($this->filesystem->exists($filename)) {
            $this->logger->info('NPM configuration found.', $context);
            return;
        }

        if ($this->write($filename, self::NPMRC_DEFAULT)) {
            $this->logger->info('NPM configuration written successfully.', $context);
            return;
        }

        $this->logger->warning('NPM configuration could not be written.', $context);
    }

    /**
     * @param string $target
     * @param string $contents
     * @return boolean
     */
    private function write($target, $contents)
    {
        try {
            $this->filesystem->dumpFile($target, $contents);

        } catch (IOException $exception) {
            return false;
        }

        return true;
    }
}
