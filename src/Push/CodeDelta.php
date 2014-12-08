<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Github\Api\Repository\Commits as CommitApi;
use Github\Exception\RuntimeException;
use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

class CodeDelta
{
    /**
     * @var string
     */
    const FS_DETAILS_FILE = '.hal9000.push.yml';

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Retrieve previous push information';
    const NO_CHANGE = 'No change. Code was redeployed.';
    const YOU_GOT_DELTAED = 'Code change found.';

    private static $spec = [
        'id',
        'source',
        'env',
        'user',
        'reference',
        'commit',
        'date'
    ];

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var CommitApi
     */
    private $commitApi;

    /**
     * @var string
     */
    private $sshUser;

    /**
     * @param EventLogger $logger
     * @param ProcessBuilder $processBuilder
     * @param Parser $parser
     * @param CommitApi $commitApi
     * @param string $sshUser
     */
    public function __construct(
        EventLogger $logger,
        ProcessBuilder $processBuilder,
        Parser $parser,
        CommitApi $commitApi,
        $sshUser
    ) {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->parser = $parser;
        $this->commitApi = $commitApi;

        $this->sshUser = $sshUser;
    }

    /**
     * @param string $hostname
     * @param string $remotePath
     * @param array $pushProperties
     * @return boolean
     */
    public function __invoke($hostname, $remotePath, array $pushProperties)
    {
        $serverCommand = 'cat ' . self::FS_DETAILS_FILE;

        // move to the application directory before command is executed
        $remoteCommand = implode(' && ', [
            sprintf('cd %s', $remotePath),
            $serverCommand
        ]);

        $command = implode(' ', [
            'ssh',
            sprintf('%s@%s', $this->sshUser, $hostname),
            sprintf('"%s"', $remoteCommand)
        ]);

        $process = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments([''])
            ->setTimeout(15)
            ->getProcess();
        $process->setCommandLine($command);

        try {
            $process->run();
        } catch (ProcessTimedOutException $ex) {
            return false;
        }

        if (!$process->isSuccessful()) {
            return false;
        }

        if (!$parsed = $this->parseYaml($process->getOutput())) {
            return false;
        }

        $hasChanged = ($parsed['commit'] != $pushProperties['commit']);

        $context = [
            'user' => $parsed['user'] ?: 'Unknown',
            'time' => $parsed['date'] ?: 'Unknown',
            'status' => $hasChanged ? self::YOU_GOT_DELTAED : self::NO_CHANGE
        ];

        // If code pushed has changed, add git info
        if ($hasChanged) {
            $context = array_merge($context, $this->gitProperties($parsed, $pushProperties));
        }

        $this->logger->event('success', self::EVENT_MESSAGE, $context);

        return true;
    }

    /**
     * @param array $old
     * @param array $new
     *
     * @return array
     */
    private function gitProperties(array $old, array $new)
    {
        $context = [
            'gitCommit' => $old['commit']
        ];

        // Add git ref if commit was not pushed
        if ($old['commit'] !== $old['reference']) {
            $context['gitReference'] = $old['reference'];
        }

        if (!$old['source'] || !$old['commit']) {
            return $context;
        }

        // Grab the user/repo pair from the source url
        $exploded = explode('/', $old['source']);
        if (count($exploded) <= 2) {
            return $context;
        }

        $repository = array_pop($exploded);
        $username = array_pop($exploded);

        // Get compare data from github api
        try {
            $comparison = $this->commitApi->compare($username, $repository, $old['commit'], $new['commit']);
        } catch (RuntimeException $e) {
            return $context;
        }

        $status = $this->nullable($comparison, 'status');

        $context['githubComparisonURL'] = $this->nullable($comparison, 'permalink_url');

        if ($status === 'behind') {
            $context['commitStatus'] = [
                'status' => $status,
                'behind_by' => $this->nullable($comparison, 'behind_by')
            ];
        } elseif ($status === 'ahead') {
            $context['commitStatus'] = [
                'status' => $status,
                'ahead_by' => $this->nullable($comparison, 'ahead_by')
            ];
        }

        return $context;
    }

    /**
     * @param string $output
     *
     * @return array|null
     */
    private function parseYaml($output)
    {
        $raw = trim($output);

        if (!$raw) {
            return null;
        }

        try {
            $yaml = $this->parser->parse($raw);
        } catch (ParseException $e) {
            return null;
        }

        if (!is_array($yaml)) {
            return null;
        }

        $default = array_fill_keys(self::$spec, null);
        return array_replace($default, $yaml);
    }

    /**
     * @param array $data
     * @param string $key
     *
     * @return mixed|null
     */
    private function nullable(array $data, $key)
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        return null;
    }
}
