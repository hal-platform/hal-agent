<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Utility;

use QL\Hal\Agent\Github\DeploymentsApi;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\User;

class GithubDeploymenter
{
    /**
     * @type DeploymentsApi
     */
    private $deploymentsApi;

    /**
     * @type string
     */
    private $halBaseUrl;

    /**
     * @type int|null
     */
    private $currentDeploymentId;

    /**
     * @type Push|null
     */
    private $currentPush;

    private static $statusDescriptions = [
        'pending' => 'Build %s is being deployed to %s (%s)',
        'success' => 'Build %s was successfully deployed to %s (%s)',
        'error' => 'An error occured while deploying Build %s to %s (%s)',
        'failure' => 'An error occured while deploying Build %s to %s (%s)'
    ];

    /**
     * @param DeploymentsApi $deploymentsApi
     * @param string $halBaseUrl
     */
    public function __construct(DeploymentsApi $deploymentsApi, $halBaseUrl)
    {
        $this->deploymentsApi = $deploymentsApi;
        $this->halBaseUrl = $halBaseUrl;
    }

    /**
     * @param Push $push
     *
     * @return boolean
     */
    public function createGitHubDeployment(Push $push)
    {
        // reset
        $this->currentPush = $this->currentDeploymentId = null;

        // no user used
        if (!$user = $push->getUser()) {
            return false;
        }

        // hal does not have github auth
        if (!$githubToken = $user->getGithubToken()) {
            return false;
        }

        $server = $push->getDeployment()->getServer();
        $env = $server->getEnvironment()->getKey();
        $server = $server->getName();

        $description = sprintf(
            '%s requested Build %s be deployed to %s (%s)',
            $user->getHandle(),
            $push->getBuild()->getId(),
            $env,
            $server
        );

        $id = $this->deploymentsApi->createDeployment(
            $push->getRepository()->getGithubUser(),
            $push->getRepository()->getGithubRepo(),
            $githubToken,
            $push->getBuild()->getCommit(),
            $env,
            $description
        );

        if ($id === null) {
            return false;
        }

        $this->currentPush = $push;
        $this->currentDeploymentId = $id;
        return true;
    }

    /**
     * Update the status of the current github deployment, if one was created.
     *
     * @param string $status
     *
     * @return void
     */
    public function updateDeployment($status)
    {
        if ($this->currentPush === null || $this->currentDeploymentId === null) {
            return;
        }

        $push = $this->currentPush;
        $deploymentId = $this->currentDeploymentId;

        // reset if not pending
        if ($status !== 'pending') {
            $this->currentPush = $this->currentDeploymentId = null;
        }

        // no user used
        if (!$user = $push->getUser()) {
            return;
        }

        // hal does not have github auth
        if (!$githubToken = $user->getGithubToken()) {
            return;
        }

        if (!array_key_exists($status, self::$statusDescriptions)) {
            return;
        }

        $server = $push->getDeployment()->getServer();
        $env = $server->getEnvironment()->getKey();
        $server = $server->getName();

        $pushUrl = sprintf(
            '%s/pushes/%s',
            rtrim($this->halBaseUrl, '/'),
            $push->getId()
        );

        $description = sprintf(
            self::$statusDescriptions[$status],
            $push->getBuild()->getId(),
            $env,
            $server
        );

        $this->deploymentsApi->createDeploymentStatus(
            $push->getRepository()->getGithubUser(),
            $push->getRepository()->getGithubRepo(),
            $githubToken,
            $deploymentId,
            $status,
            $pushUrl,
            $description
        );
    }
}
