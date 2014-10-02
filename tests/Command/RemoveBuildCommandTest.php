<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Build;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RemoveBuildCommandTest extends PHPUnit_Framework_TestCase
{
    public $em;
    public $buildRepo;
    public $filesystem;
    public $archive;

    public $output;

    public function setUp()
    {
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager');
        $this->buildRepo = Mockery::mock('QL\Hal\Core\Entity\Repository\BuildRepository');
        $this->filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');
        $this->archive = 'path';

        $this->output = new BufferedOutput;
    }

    public function testBuildNotFound()
    {
        $this->buildRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $input = new ArrayInput([
            'BUILD_ID' => ['1']
        ]);

        $command = new RemoveBuildCommand(
            'derp:cmd',
            $this->em,
            $this->buildRepo,
            $this->filesystem,
            $this->archive
        );

        $command->run($input, $this->output);

        $expected = <<<'OUTPUT'
Build "1" not found.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testNotSuccessfulBuildCannotBeRemoved()
    {
        $build = new Build;

        $this->buildRepo
            ->shouldReceive('find')
            ->andReturn($build);

        $input = new ArrayInput([
            'BUILD_ID' => ['1']
        ]);

        $command = new RemoveBuildCommand(
            'derp:cmd',
            $this->em,
            $this->buildRepo,
            $this->filesystem,
            $this->archive
        );

        $command->run($input, $this->output);

        $expected = <<<'OUTPUT'
Build "1" must be status "Success" to be removed.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testMissingArchiveStillUpdatesEntity()
    {
        $build = new Build;
        $build->setStatus('Success');

        $this->buildRepo
            ->shouldReceive('find')
            ->andReturn($build);

        $this->em
            ->shouldReceive('merge')
            ->once();
        $this->em
            ->shouldReceive('flush')
            ->once();
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(false);

        $input = new ArrayInput([
            'BUILD_ID' => ['1']
        ]);

        $command = new RemoveBuildCommand(
            'derp:cmd',
            $this->em,
            $this->buildRepo,
            $this->filesystem,
            $this->archive
        );

        $command->run($input, $this->output);

        $expected = <<<'OUTPUT'
Archive for build "1" was already removed.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
        $this->assertSame('Removed', $build->getStatus());
    }
}
