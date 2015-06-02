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
        if (!$user = $push->user()) {
            return false;
        }

        // hal does not have github auth
        if (!$githubToken = $user->githubToken()) {
            return false;
        }

        $server = $push->deployment()->server();
        $env = $server->environment()->name();
        $server = $server->name();

        $description = sprintf(
            '%s requested Build %s be deployed to %s (%s)',
            $user->handle(),
            $push->build()->id(),
            $env,
            $server
        );

        $id = $this->deploymentsApi->createDeployment(
            $push->application()->githubOwner(),
            $push->application()->githubRepo(),
            $githubToken,
            $push->build()->commit(),
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
        if (!$user = $push->user()) {
            return;
        }

        // hal does not have github auth
        if (!$githubToken = $user->githubToken()) {
            return;
        }

        if (!array_key_exists($status, self::$statusDescriptions)) {
            return;
        }

        $server = $push->deployment()->server();
        $env = $server->environment()->name();
        $server = $server->name();

        $pushUrl = sprintf(
            '%s/pushes/%s',
            rtrim($this->halBaseUrl, '/'),
            $push->id()
        );

        $description = sprintf(
            self::$statusDescriptions[$status],
            $push->build()->id(),
            $env,
            $server
        );

        $this->deploymentsApi->createDeploymentStatus(
            $push->application()->githubOwner(),
            $push->application()->githubRepo(),
            $githubToken,
            $deploymentId,
            $status,
            $pushUrl,
            $description
        );
    }
}
