<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Unix;

use Mockery;
use PHPUnit_Framework_TestCase;

class DockerBuilderTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $remoter;
    public $buildRemoter;
    public $dockerSourcesPath;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->remoter = Mockery::mock('QL\Hal\Agent\Remoting\SSHProcess');
        $this->buildRemoter = Mockery::mock('QL\Hal\Agent\Remoting\SSHProcess');
        $this->dockerSourcesPath = '/docker-images';
    }

    public function testSuccess()
    {
        $this->remoter
            ->shouldReceive('__invoke')
            ->times(7)
            ->andReturn(true);
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(3)
            ->andReturn('owner', 'group', 'container-name');

        $this->buildRemoter
            ->shouldReceive('__invoke')
            ->times(2)
            ->andReturn(true);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertTrue($success);
    }

    public function testFailAtSanityCheck()
    {
        $this->remoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->andReturn(false);

        $this->buildRemoter
            ->shouldReceive('__invoke')
            ->never();

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtBuildImage()
    {
        $expectedCommand = 'docker build --tag="hal9000/unix" "/docker-images/unix"';
        $this->remoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->andReturn(true);
        $this->buildRemoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->with('builduser', 'buildserver', $expectedCommand, [], true, null, Mockery::any())
            ->andReturn(false);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtGetMeta()
    {
        $expectedCommand = 'ls -ldn buildpath | awk \'{print $3}\'';

        $this->remoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->andReturn(true)
            ->ordered();
        $this->buildRemoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->andReturn(true);

        // meta
        $this->remoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->with('builduser', 'buildserver', $expectedCommand, [], false, null, Mockery::any())
            ->andReturn(true)
            ->ordered();
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(1)
            ->andReturn('builduser-owner');
        $this->remoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->andReturn(false)
            ->ordered();

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtStartContainer()
    {
        $expectedCommand = implode(' ', [
            'docker run',
            '--detach=true',
            '--tty=true',
            '--interactive=true',
            '--volume="buildpath:/build"',
            '--workdir="/build"',
            '--env HAL_DERP',
            'hal9000/unix',
            'bash -l'
        ]);

        $this->remoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->andReturn(true)
            ->ordered();
        $this->buildRemoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->andReturn(true);

        // meta
        $this->remoter
            ->shouldReceive('__invoke')
            ->times(2)
            ->andReturn(true)
            ->ordered();
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(2)
            ->andReturn('builduser-owner', 'builduser-group');

        // start
        $this->remoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->with('builduser', 'buildserver', $expectedCommand, ['HAL_DERP' => 'testing'], false, null, Mockery::any())
            ->andReturn(false)
            ->ordered();

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], ['HAL_DERP' => 'testing']);
        $this->assertFalse($success);
    }

    public function testFailAtRunCommand()
    {
        $expectedPrefix = implode(' ', [
            'docker exec',
            '"container-name"'
        ]);

        $expectedCommand = "sh -c 'command'";

        // build image
        $this->buildRemoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->andReturn(true)
            ->ordered();

        // sanity, meta, start
        $this->remoter
            ->shouldReceive('__invoke')
            ->times(7)
            ->andReturn(true)
            ->ordered();
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(3)
            ->andReturn('builduser-owner', 'builduser-group', 'container-name');

        // run
        $this->buildRemoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->with('builduser', 'buildserver', $expectedCommand, [], true, $expectedPrefix, Mockery::any())
            ->andReturn(false)
            ->ordered();

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);
        $action->disableShutdownHandler();

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertFalse($success);
    }

    public function testMultipleCommandsAreRun()
    {
        $expectedPrefix = implode(' ', [
            'docker exec',
            '"container-name"'
        ]);

        $expectedCommand1 = "sh -c 'command1'";
        $expectedCommand2 = "sh -c 'command2'";

        // build image
        $this->buildRemoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->andReturn(true)
            ->ordered();

        // sanity, meta, start
        $this->remoter
            ->shouldReceive('__invoke')
            ->times(7)
            ->andReturn(true)
            ->ordered();
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(3)
            ->andReturn('builduser-owner', 'builduser-group', 'container-name');

        // run
        $this->buildRemoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->with('builduser', 'buildserver', $expectedCommand1, [], true, $expectedPrefix, Mockery::any())
            ->andReturn(true)
            ->ordered();

        $this->buildRemoter
            ->shouldReceive('__invoke')
            ->times(1)
            ->with('builduser', 'buildserver', $expectedCommand2, [], true, $expectedPrefix, Mockery::any())
            ->andReturn(true)
            ->ordered();

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);
        $action->disableShutdownHandler();

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command1', 'command2'], []);

        $this->assertSame(true, $success);
    }
}
