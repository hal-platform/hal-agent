<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\Rsync;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Remoting\SSHProcess;
use QL\Hal\Agent\Remoting\SSHSessionManager;

/**
 * Ugh http://unix.stackexchange.com/questions/42685/rsync-how-to-exclude-the-topmost-directory
 */
class Verify
{
    const EVENT_MESSAGE = 'Verify connection to server';
    const CREATE_DIR = 'Create target directory';
    const ERR_READ_PERMISSIONS = 'Could not read permissions of target directory';
    const ERR_VERIFY_PERMISSIONS = 'Could not verify permissions of target directory';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var SSHSessionManager
     */
    private $sshManager;

    /**
     * @var SSHProcess
     */
    private $remoter;

    /**
     * @param EventLogger $logger
     * @param SSHSessionManager $sshManager
     * @param SSHProcess $remoter
     */
    public function __construct(EventLogger $logger, SSHSessionManager $sshManager, SSHProcess $remoter)
    {
        $this->logger = $logger;
        $this->sshManager = $sshManager;
        $this->remoter = $remoter;
    }

    /**
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     *
     * @return bool
     */
    public function __invoke($remoteUser, $remoteServer, $remotePath)
    {
        if (!$this->verifyConnectability($remoteUser, $remoteServer)) {
            return false;
        }

        if (!$this->verifyTargetExists($remoteUser, $remoteServer, $remotePath)) {
            return false;
        }

        if (!$this->verifyTargetIsWriteable($remoteUser, $remoteServer, $remotePath)) {
            return false;
        }

        $this->logger->event('success', self::EVENT_MESSAGE);

        // all good
        return true;
    }

    /**
     * @param string $remoteUser
     * @param string $remoteServer
     *
     * @return bool
     */
    private function verifyConnectability($remoteUser, $remoteServer)
    {
        if (!$ssh = $this->sshManager->createSession($remoteUser, $remoteServer)) {
            $this->logger->event('failure', self::EVENT_MESSAGE, ['errors' => $this->sshManager->getErrors()]);
            return false;
        }

        return true;
    }

    /**
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $target
     *
     * @return bool
     */
    private function verifyTargetExists($remoteUser, $remoteServer, $target)
    {
        $dirExists = sprintf('test -d "%s"', $target);
        $mkDir = sprintf('mkdir "%s"', $target);

        $context = $this->remoter->createCommand($remoteUser, $remoteServer, $dirExists);
        if (!$response = $this->remoter->run($context, [], [false])) {
            // does not exist, try creating
            $context = $this->remoter->createCommand($remoteUser, $remoteServer, $mkDir);
            if (!$response = $this->remoter->run($context, [], [true, self::CREATE_DIR])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $target
     *
     * @return bool
     */
    private function verifyTargetIsWriteable($remoteUser, $remoteServer, $target)
    {
        $dirWriteable = sprintf('test -w "%s"', $target);
        $getTargetStats = sprintf('ls -ld "%s"', $target);
        $verifyOwner = sprintf('find "%s" -maxdepth 0 -user "%s" -d 0 -type d -print0', $target, $remoteUser);

        $context = $this->remoter->createCommand($remoteUser, $remoteServer, $dirWriteable);
        $isWriteable = $this->remoter->run($context, [], [false]);

        // Get the ls metadata for log output
        $context = $this->remoter->createCommand($remoteUser, $remoteServer, $getTargetStats);
        if (!$response = $this->remoter->run($context, [], [false])) {
            $this->logger->event('failure', self::ERR_READ_PERMISSIONS, ['directory' => $target]);
            return false;
        }

        $output = trim($this->remoter->getLastOutput());

        $context = $this->remoter->createCommand($remoteUser, $remoteServer, $verifyOwner);
        $isOwned = $this->remoter->run($context, [], [false]);

        if (!$isWriteable || !$isOwned) {
            $this->logger->event('failure', self::ERR_VERIFY_PERMISSIONS, [
                'directory' => $target,
                'currentPermissions' => $output,
                'requiredOwner' => $remoteUser,
                'isWriteable' => $isWriteable ? 'Yes' : 'No'
            ]);
            return false;
        }

        return true;
    }
}
