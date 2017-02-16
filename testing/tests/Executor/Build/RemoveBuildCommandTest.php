<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Management;

use Hal\Agent\Executor\Management\RemoveBuildCommand;
use Hal\Agent\Testing\ExecutorTestCase;
use Mockery;
use QL\Hal\Core\Entity\Build;

class RemoveBuildCommandTest extends ExecutorTestCase
{
    public $em;
    public $buildRepo;
    public $filesystem;
    public $archive;

    public $output;

    public function setUp()
    {
        $this->buildRepo = Mockery::mock('QL\Hal\Core\Repository\BuildRepository');
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager', [
            'getRepository' => $this->buildRepo
        ]);
        $this->filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');
        $this->archive = 'path';
    }

    public function configureCommand($c)
    {
        RemoveBuildCommand::configure($c);
    }

    public function testMultiRemoveBuildSuccessWhenFoundAndRemoved()
    {
        $build = new Build;
        $build->withStatus('Success');

        $build2 = new Build;
        $build2->withStatus('Success');

        $this->buildRepo
            ->shouldReceive('find')
            ->with(1)
            ->andReturn($build);
        $this->buildRepo
            ->shouldReceive('find')
            ->with(2)
            ->andReturn($build2);

        $this->em
            ->shouldReceive('merge');
        $this->em
            ->shouldReceive('flush');
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->filesystem
            ->shouldReceive('remove')
            ->andReturn(true);

        $io = $this->io('configureCommand', ['BUILD_ID' => ['1', '2']]);

        $command = new RemoveBuildCommand(
            $this->em,
            $this->filesystem,
            $this->archive
        );

        $exit = $command->execute($io);

        $expected = [
            '[OK] ' . sprintf(RemoveBuildCommand::SUCCESS_MSG, 1),
            '[OK] ' . sprintf(RemoveBuildCommand::SUCCESS_MSG, 2),
            '[OK] ' . sprintf(RemoveBuildCommand::REMOVED_SUMMARY, 2, 2, 0)
        ];

        $output = $this->output();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }

        $this->assertSame('Removed', $build->status());
        $this->assertSame('Removed', $build2->status());
        $this->assertSame(0, $exit);
    }

    public function testSingleRemoveBuildSuccessWhenFoundAndRemoved()
    {
        $build = new Build;
        $build->withStatus('Success');

        $this->buildRepo
            ->shouldReceive('find')
            ->with(1)
            ->andReturn($build);

        $this->em
            ->shouldReceive('merge')
            ->once();
        $this->em
            ->shouldReceive('flush')
            ->once();
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->filesystem
            ->shouldReceive('remove')
            ->andReturn(true);

        $io = $this->io('configureCommand', ['BUILD_ID' => ['1']]);

        $command = new RemoveBuildCommand(
            $this->em,
            $this->filesystem,
            $this->archive
        );

        $exit = $command->execute($io);

        $expected = '[OK] ' . sprintf(RemoveBuildCommand::SUCCESS_MSG, 1);

        $output = $this->output();
        $this->assertContains($expected, $output);

        $this->assertSame('Removed', $build->status());
        $this->assertSame(0, $exit);
    }

    public function testNoBuildIdSupplied()
    {
        $this->buildRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $io = $this->io('configureCommand', ['BUILD_ID' => []]);

        $command = new RemoveBuildCommand(
            $this->em,
            $this->filesystem,
            $this->archive
        );

        $exit = $command->execute($io);

        $output = $this->output();
        $expected = '[ERROR] ' . sprintf(RemoveBuildCommand::ERR_MISSING_BUILD_ARGS);

        $this->assertContains($expected, $output);
        $this->assertSame(1, $exit);
    }

    public function testBuildNotFound()
    {
        $this->buildRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $io = $this->io('configureCommand', ['BUILD_ID' => ['1']]);

        $command = new RemoveBuildCommand(
            $this->em,
            $this->filesystem,
            $this->archive
        );

        $exit = $command->execute($io);

        $output = $this->output();
        $expected = '[ERROR] Build "1" not found.';

        $this->assertContains($expected, $output);
        $this->assertSame(1, $exit);
    }

    public function testNotSuccessfulOrErrorBuildCannotBeRemoved()
    {
        $build = new Build;

        $this->buildRepo
            ->shouldReceive('find')
            ->andReturn($build);

        $io = $this->io('configureCommand', ['BUILD_ID' => ['1']]);

        $command = new RemoveBuildCommand(
            $this->em,
            $this->filesystem,
            $this->archive
        );

        $exit = $command->execute($io);

        $expected = '[ERROR] '. sprintf(RemoveBuildCommand::ERR_NOT_FINISHED, 1);

        $output = $this->output();
        $this->assertContains($expected, $output);
        $this->assertSame($exit, 1);
    }

    public function testMissingArchiveStillUpdatesEntity()
    {
        $build = new Build;
        $build->withStatus('Success');

        $build2 = new Build;
        $build2->withStatus('Success');

        $this->buildRepo
            ->shouldReceive('find')
            ->with(1)
            ->andReturn($build);

        $this->buildRepo
            ->shouldReceive('find')
            ->with(2)
            ->andReturn($build2);

        $this->em
            ->shouldReceive('merge');
        $this->em
            ->shouldReceive('flush');
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(false);

        $io = $this->io('configureCommand', ['BUILD_ID' => ['1', '2']]);

        $command = new RemoveBuildCommand(
            $this->em,
            $this->filesystem,
            $this->archive
        );

        $exit = $command->execute($io);

        $expected = [
            '[ERROR] ' . sprintf(RemoveBuildCommand::ERR_ALREADY_REMOVED, 1),
            '[ERROR] ' . sprintf(RemoveBuildCommand::ERR_ALREADY_REMOVED, 2),
            '[ERROR] ' . sprintf(RemoveBuildCommand::REMOVED_SUMMARY, 2, 0, 2)
        ];

        $output = $this->output();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }

        $this->assertSame('Removed', $build->status());
        $this->assertSame('Removed', $build2->status());
        $this->assertSame($exit, 1);
    }

    public function testSingleSuccess()
    {

    }
}
