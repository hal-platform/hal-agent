<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Utility;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Repository;
use QL\Hal\Core\Entity\Server;
use QL\Hal\Core\Entity\User;

class GithubDeploymenterTest extends PHPUnit_Framework_TestCase
{
    public $api;

    public function setUp()
    {
        $this->api = Mockery::mock('QL\Hal\Agent\Github\DeploymentsApi');
    }

    public function testCreatingWithoutUserFailsGracefully()
    {
        $push = $this->createMockPush();

        $this->api
            ->shouldReceive('createDeployment')
            ->never();

        $deployer = new GithubDeploymenter($this->api, 'http://baseurl/');
        $actual = $deployer->createGitHubDeployment($push);

        $this->assertSame(false, $actual);
    }

    public function testCreatingWithoutTokenFailsGracefully()
    {
        $user = new User;
        $push = $this->createMockPush();
        $push->setUser($user);

        $this->api
            ->shouldReceive('createDeployment')
            ->never();

        $deployer = new GithubDeploymenter($this->api, 'http://baseurl/');
        $actual = $deployer->createGitHubDeployment($push);

        $this->assertSame(false, $actual);
    }

    public function testCreatingDeploymentFailure()
    {
        $user = new User;
        $user->setHandle('testuser');
        $user->setGithubToken('token1234');

        $push = $this->createMockPush();
        $push->setUser($user);

        $this->api
            ->shouldReceive('createDeployment')
            ->with(
                'user1',
                'repo1',
                'token1234',
                '5555',
                'envname',
                'testuser requested Build build-1234 be deployed to envname (testserver1)'
            )
            ->andReturnNull()
            ->once();

        $deployer = new GithubDeploymenter($this->api, 'http://baseurl/');
        $actual = $deployer->createGitHubDeployment($push);

        $this->assertSame(false, $actual);
    }

    public function testCreatingDeploymentSuccess()
    {
        $user = new User;
        $user->setHandle('testuser');
        $user->setGithubToken('token1234');

        $push = $this->createMockPush();
        $push->setUser($user);

        $this->api
            ->shouldReceive('createDeployment')
            ->with(
                'user1',
                'repo1',
                'token1234',
                '5555',
                'envname',
                'testuser requested Build build-1234 be deployed to envname (testserver1)'
            )
            ->andReturn(5678)
            ->once();

        $deployer = new GithubDeploymenter($this->api, 'http://baseurl/');
        $actual = $deployer->createGitHubDeployment($push);

        $this->assertSame(true, $actual);
    }

    public function testCreatingStatusWithoutPreviouslyCreatingDeploymentFailsGracefully()
    {
        $this->api
            ->shouldReceive('createDeploymentStatus')
            ->never();

        $deployer = new GithubDeploymenter($this->api, 'http://baseurl/');
        $actual = $deployer->updateDeployment('failure');

        $this->assertSame(null, $actual);
    }

    public function testCreatingBadStatusFailsGracefully()
    {
        $user = new User;
        $user->setGithubToken('token1234');
        $push = $this->createMockPush();
        $push->setUser($user);

        $this->api
            ->shouldReceive('createDeployment')
            ->andReturn(55)
            ->once();
        $this->api
            ->shouldReceive('createDeploymentStatus')
            ->never();

        $deployer = new GithubDeploymenter($this->api, 'http://baseurl/');
        $deployer->createGitHubDeployment($push);
        $actual = $deployer->updateDeployment('derp');

        $this->assertSame(null, $actual);
    }

    public function testCreatingDeploymentStatusSuccess()
    {
        $user = new User;
        $user->setHandle('testuser');
        $user->setGithubToken('token1234');

        $push = $this->createMockPush();
        $push->setUser($user);

        $this->api
            ->shouldReceive('createDeployment')
            ->andReturn(66)
            ->once();
        $this->api
            ->shouldReceive('createDeploymentStatus')
            ->with(
                'user1',
                'repo1',
                'token1234',
                66,
                'failure',
                'http://baseurl/pushes/push-1234',
                'An error occured while deploying Build build-1234 to envname (testserver1)'
            )
            ->once();

        $deployer = new GithubDeploymenter($this->api, 'http://baseurl/');
        $deployer->createGitHubDeployment($push);
        $actual = $deployer->updateDeployment('failure');

        $this->assertSame(null, $actual);
    }

    private function createMockPush()
    {
        $environment = new Environment;
        $environment->setKey('envname');

        $repository = new Repository;
        $repository->setGithubUser('user1');
        $repository->setGithubRepo('repo1');
        $repository->setKey('repokey');

        $build = new Build;
        $build->setId('build-1234');
        $build->setCommit('5555');

        $server = new Server;
        $server->setName('testserver1');
        $server->setEnvironment($environment);

        $deployment = new Deployment;
        $deployment->setServer($server);

        $push = new Push;
        $push->setId('push-1234');
        $push->setRepository($repository);
        $push->setDeployment($deployment);
        $push->setBuild($build);

        return $push;
    }

}
