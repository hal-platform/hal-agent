<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Unix;

use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class PackageManagerPreparer
{
    const EVENT_MESSAGE = 'Write Composer configuration';

    /**
     * @type string
     */
    const COMPOSER_CONFIG_FS = '%s/config.json';

    /**
     * $COMPOSER_HOME/config.json
     *
     * @type string
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
     * @type EventLogger
     */
    private $logger;

    /**
     * @type Filesystem
     */
    private $filesystem;

    /**
     * @type string
     */
    private $githubAuthToken;

    /**
     * @param EventLogger $logger
     * @param Filesystem $filesystem
     * @param string $githubAuthToken
     */
    public function __construct(EventLogger $logger, Filesystem $filesystem, $githubAuthToken)
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
        $composerConfig = sprintf(self::COMPOSER_CONFIG_FS, rtrim($environment['COMPOSER_HOME'], '/'));

        $this->handleComposerConfiguration($composerConfig);
    }

    /**
     * @param string $filename
     * @return null
     */
    private function handleComposerConfiguration($filename)
    {
        if ($this->filesystem->exists($filename)) {
            return;
        }

        $config = sprintf(self::COMPOSER_CONFIG_DEFAULT, $this->githubAuthToken);
        if ($this->write($filename, $config)) {
            $this->logger->event('success', self::EVENT_MESSAGE, [
                'composerConfig' => $filename
            ]);

            return;
        }

        $this->logger->event('failure', self::EVENT_MESSAGE, [
            'composerConfig' => $filename
        ]);
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
