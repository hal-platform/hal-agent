<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Utility;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
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
        $push = $this->createMockPush()
            ->withUser(new User);

        $this->api
            ->shouldReceive('createDeployment')
            ->never();

        $deployer = new GithubDeploymenter($this->api, 'http://baseurl/');
        $actual = $deployer->createGitHubDeployment($push);

        $this->assertSame(false, $actual);
    }

    public function testCreatingDeploymentFailure()
    {
        $push = $this->createMockPush()
            ->withUser(
                (new User)
                    ->withHandle('testuser')
                    ->withGithubToken('token1234')

            );

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
        $push = $this->createMockPush()
            ->withUser(
                (new User)
                    ->withHandle('testuser')
                    ->withGithubToken('token1234')

            );

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
        $push = $this->createMockPush()
            ->withUser(
                (new User)
                    ->withGithubToken('token1234')

            );

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
        $push = $this->createMockPush()
            ->withUser(
                (new User)
                    ->withHandle('testuser')
                    ->withGithubToken('token1234')

            );

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
        $push = (new Push)
            ->withId('push-1234')
            ->withApplication(
                (new Application)
                    ->withGithubOwner('user1')
                    ->withGithubRepo('repo1')
                    ->withKey('repokey')
            )
            ->withDeployment(
                (new Deployment)
                    ->withServer(
                        (new Server)
                            ->withName('testserver1')
                            ->withEnvironment(
                                (new Environment)
                                    ->withName('envname')
                            )
                    )
            )
            ->withBuild(
                (new Build)
                    ->withId('build-1234')
                    ->withCommit('5555')

            );

        return $push;
    }
}
