<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux;

use Hal\Agent\Docker\DockerImageValidator;
use Hal\Agent\Docker\LinuxDockerinator;
use Hal\Agent\Job\FileCompression;
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
    public $compression;
    public $steps;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->docker = Mockery::mock(LinuxDockerinator::class, [
            'cleanupContainer' => true
        ]);
        $this->validator = Mockery::mock(DockerImageValidator::class);
        $this->compression = Mockery::mock(FileCompression::class);
        $this->steps = Mockery::mock(StepParser::class);
    }

    public function testSuccess()
    {
        $this->compression
            ->shouldReceive('packTarArchive')
            ->with('/jobs/1234', Mockery::pattern('#^/workspace/1234/([a-z0-9\-]{36}).tgz$#'))
            ->andReturn(true);

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
            ->shouldReceive('createVolume')
            ->with('j-1234-stage')
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('createContainer')
            ->with('my-image:latest', 'j-1234', 'j-1234-stage')
            ->times(2)
            ->andReturn(true);
        $this->docker
            ->shouldReceive('createContainer')
            ->with('my-image:latest', 'j-1234', 'j-1234-stage', ['TEST_VAR' => '1234'], 'step1')
            ->once()
            ->andReturn(true);
        $this->docker
            ->shouldReceive('createContainer')
            ->with('my-image:latest', 'j-1234', 'j-1234-stage', ['TEST_VAR' => '1234'], 'step2 --flag')
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->with('j-1234', Mockery::pattern('#^/workspace/1234/([a-z0-9\-]{36}).tgz$#'))
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('startUserContainer')
            ->with('j-1234', 'step1', 'Build step [1/2] "step1"')
            ->once()
            ->andReturn(true);
        $this->docker
            ->shouldReceive('startUserContainer')
            ->with('j-1234', 'step2 --flag', 'Build step [2/2] "step2 --flag"')
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyFromContainer')
            ->with('j-1234', Mockery::pattern('#^/workspace/1234/([a-z0-9\-]{36}).tgz$#'))
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('cleanupContainer')
            ->with('j-1234')
            ->times(4);
        $this->docker
            ->shouldReceive('cleanupVolume')
            ->with('j-1234-stage')
            ->once();

        $this->compression
            ->shouldReceive('remove')
            ->with('/jobs/1234')
            ->andReturn(true);
        $this->compression
            ->shouldReceive('createWorkspace')
            ->with('/jobs/1234')
            ->andReturn(true);
        $this->compression
            ->shouldReceive('unpackTarArchive')
            ->with('/jobs/1234', Mockery::pattern('#^/workspace/1234/([a-z0-9\-]{36}).tgz$#'))
            ->andReturn(true);

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->compression, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            '/workspace/1234',
            '/jobs/1234',
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

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->compression, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            '/workspace/1234',
            '/jobs/1234',
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

        $this->compression
            ->shouldReceive('packTarArchive')
            ->with('/jobs/1234', Mockery::pattern('#^/workspace/1234/([a-z0-9\-]{36}).tgz$#'))
            ->andReturn(true);

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->docker
            ->shouldReceive('createVolume')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn(false);

        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->never();

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->compression, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            '/workspace/1234',
            '/jobs/1234',
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

        $this->compression
            ->shouldReceive('packTarArchive')
            ->andReturn(true);

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->docker
            ->shouldReceive('createVolume')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn(true);
        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->once()
            ->andReturn(false);

        $this->docker
            ->shouldReceive('startUserContainer')
            ->never();

        $this->docker
            ->shouldReceive('cleanupVolume')
            ->with('j-1234-stage')
            ->once();

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->compression, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            '/workspace/1234',
            '/jobs/1234',
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

        $this->compression
            ->shouldReceive('packTarArchive')
            ->andReturn(true);

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->docker
            ->shouldReceive('createVolume')
            ->andReturn(true);
        $this->docker
            ->shouldReceive('createContainer')
            ->andReturn(true);
        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->andReturn(true);
        $this->docker
            ->shouldReceive('startUserContainer')
            ->once()
            ->andReturn(false);
        $this->docker
            ->shouldReceive('cleanupVolume')
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('info', 'Skipping 1 remaining build steps')
            ->once();

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->compression, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            '/workspace/1234',
            '/jobs/1234',
            ['step1'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }

    public function testFailOnBuildStep()
    {
        $this->validator
            ->shouldReceive('validate')
            ->andReturn('my-image:latest');

        $this->compression
            ->shouldReceive('packTarArchive')
            ->andReturn(true);

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
            ->shouldReceive('createVolume')
            ->andReturn(true);
        $this->docker
            ->shouldReceive('createContainer')
            ->andReturn(true);
        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->andReturn(true);
        $this->docker
            ->shouldReceive('startUserContainer')
            ->once()
            ->andReturn(false);

        $this->docker
            ->shouldReceive('copyFromContainer')
            ->never();

        $this->docker
            ->shouldReceive('cleanupVolume')
            ->once();

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->compression, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            '/workspace/1234',
            '/jobs/1234',
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

        $this->compression
            ->shouldReceive('packTarArchive')
            ->andReturn(true);

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->docker
            ->shouldReceive('createVolume')
            ->andReturn(true);
        $this->docker
            ->shouldReceive('createContainer')
            ->andReturn(true);
        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->andReturn(true);
        $this->docker
            ->shouldReceive('startUserContainer')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyFromContainer')
            ->once()
            ->andReturn(false);

        $this->docker
            ->shouldReceive('cleanupVolume')
            ->once();

        $builder = new DockerBuilder($this->logger, $this->docker, $this->validator, $this->compression, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'j-1234',
            'my-image:latest',
            '/workspace/1234',
            '/jobs/1234',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }
}
