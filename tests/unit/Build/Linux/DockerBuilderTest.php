<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux;

use Hal\Agent\Docker\DockerImageValidator;
use Hal\Agent\Docker\LinuxDockerinator;
use Hal\Agent\JobConfiguration\StepParser;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\IOTestCase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class DockerBuilderTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $docker;
    public $validator;
    public $steps;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->docker = Mockery::mock(LinuxDockerinator::class, [
            'cleanupContainer' => true
        ]);
        $this->validator = Mockery::mock(DockerImageValidator::class);
        $this->steps = Mockery::mock(StepParser::class);
    }

    public function testSuccess()
    {
        $this->validator
            ->shouldReceive('validate')
            ->with('my-image:latest')
            ->andReturn('my-image:latest');

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->with('my-image:latest', ['step1', 'step2 --flag'])
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->docker
            ->shouldReceive('createContainer')
            ->with('user@localhost', 'my-image:latest', 'j-1234', ['TEST_VAR' => '1234'])
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->with('user@localhost', 'j-1234', 'j-1234', '/tmp/buildfile.tar.gz')
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('startContainer')
            ->with('user@localhost', 'j-1234')
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('runCommand')
            ->with('user@localhost', 'j-1234', 'step1', 'Build step [1/2] "step1"')
            ->once()
            ->andReturn(true);
        $this->docker
            ->shouldReceive('runCommand')
            ->with('user@localhost', 'j-1234', 'step2 --flag', 'Build step [2/2] "step2 --flag"')
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyFromContainer')
            ->with('user@localhost', 'j-1234', '/tmp/buildfile.tar.gz')
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('cleanupContainer')
            ->with('user@localhost', 'j-1234')
            ->once();

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            'user@localhost',
            '/tmp/buildfile.tar.gz',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $expected = [
            '! [NOTE] Starting Docker container',
            '! [NOTE] Docker container "j-1234" started',
            '* Running build step [ [1/2] step1 ] in Linux Docker container',
            '* Running build step [ [2/2] step2 --flag ] in Linux Docker container',
            '! [NOTE] Cleaning up Docker container "j-1234"'
        ];

        $this->assertSame(true, $success);
        $this->assertContainsLines($expected, $this->output());
    }

    public function testFailOnValidateImage()
    {
        $this->validator
            ->shouldReceive('validate')
            ->with('my-image:latest')
            ->andReturn(false);

        // next thing never runs
        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->never();

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            'user@localhost',
            '/tmp/buildfile.tar.gz',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }

    public function testFailOnCreateContainer()
    {
        $this->validator
            ->shouldReceive('validate')
            ->andReturn('my-image:latest');

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->docker
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn(false);

        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->never();

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            'user@localhost',
            '/tmp/buildfile.tar.gz',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }

    public function testFailAtCopyIn()
    {
        $this->validator
            ->shouldReceive('validate')
            ->andReturn('my-image:latest');

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->docker
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn(true);
        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->once()
            ->andReturn(false);

        $this->docker
            ->shouldReceive('runCommand')
            ->never();

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            'user@localhost',
            '/tmp/buildfile.tar.gz',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }

    public function testFailOnStartContainer()
    {
        $this->validator
            ->shouldReceive('validate')
            ->andReturn('my-image:latest');

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->docker
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn(true);
        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->once()
            ->andReturn(true);
        $this->docker
            ->shouldReceive('startContainer')
            ->once()
            ->andReturn(false);

        $this->docker
            ->shouldReceive('runCommand')
            ->never();

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            'user@localhost',
            '/tmp/buildfile.tar.gz',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }

    public function testFailOnBuildStep()
    {
        $this->validator
            ->shouldReceive('validate')
            ->andReturn('my-image:latest');

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);
        $this->logger
            ->shouldReceive('event')
            ->with('info', 'Skipping 1 remaining build steps')
            ->once();

        $this->docker
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn(true);
        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->once()
            ->andReturn(true);
        $this->docker
            ->shouldReceive('startContainer')
            ->once()
            ->andReturn(true);
        $this->docker
            ->shouldReceive('runCommand')
            ->once()
            ->andReturn(false);

        $this->docker
            ->shouldReceive('copyFromContainer')
            ->never();

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            'user@localhost',
            '/tmp/buildfile.tar.gz',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }

    public function testFailOnCopyFromContainer()
    {
        $this->validator
            ->shouldReceive('validate')
            ->andReturn('my-image:latest');

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->docker
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn(true);
        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->once()
            ->andReturn(true);
        $this->docker
            ->shouldReceive('startContainer')
            ->once()
            ->andReturn(true);
        $this->docker
            ->shouldReceive('runCommand')
            ->twice()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyFromContainer')
            ->once()
            ->andReturn(false);

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            'user@localhost',
            '/tmp/buildfile.tar.gz',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }
}
