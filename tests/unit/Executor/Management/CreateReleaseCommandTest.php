<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Management;

use Doctrine\ORM\EntityManager;
use Hal\Agent\Testing\ExecutorTestCase;
use Mockery;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Target;
use Hal\Core\Repository\BuildRepository;
use Hal\Core\Repository\TargetRepository;
use QL\MCP\Common\Time\Clock;

class CreateReleaseCommandTest extends ExecutorTestCase
{
    public $em;
    public $buildRepo;
    public $releaseRepo;
    public $clock;
    public $unique;

    public function setUp()
    {
        $this->em = Mockery::mock(EntityManager::class);
        $this->buildRepo = Mockery::mock(BuildRepository::class);
        $this->releaseRepo = Mockery::mock(TargetRepository::class);

        $this->em
            ->shouldReceive('getRepository')
            ->with(Build::class)
            ->andReturn($this->buildRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(Target::class)
            ->andReturn($this->releaseRepo);

        $this->clock = new Clock('now', 'UTC');
    }

    public function configureCommand($c)
    {
        CreateReleaseCommand::configure($c);
    }

    public function testBuildNotFound()
    {
        $this->buildRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $command = new CreateReleaseCommand($this->em, $this->clock);

        $io = $this->io('configureCommand', [
            'BUILD_ID' => '1',
            'TARGET_ID' => '2'
        ]);
        $exit = $command->execute($io);

        $expected = [
            '[ERROR] Build not found.'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }

    public function testDeploymentNotFound()
    {
        $build = new Build;
        $build->withStatus('Success');

        $this->buildRepo
            ->shouldReceive('find')
            ->andReturn($build);

        $this->releaseRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $command = new CreateReleaseCommand($this->em, $this->clock);

        $io = $this->io('configureCommand', [
            'BUILD_ID' => '1',
            'TARGET_ID' => '2'
        ]);
        $exit = $command->execute($io);

        $expected = [
            '[ERROR] Release target not found. '
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }
}
