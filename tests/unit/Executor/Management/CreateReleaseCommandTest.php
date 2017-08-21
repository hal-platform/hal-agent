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
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\JobIdGenerator;
use QL\Hal\Core\Repository\BuildRepository;
use QL\Hal\Core\Repository\DeploymentRepository;
use QL\MCP\Common\Time\Clock;

class CreateReleaseCommandTest extends ExecutorTestCase
{
    public $em;
    public $buildRepo;
    public $deployRepo;
    public $clock;
    public $unique;

    public function setUp()
    {
        $this->em = Mockery::mock(EntityManager::class);
        $this->buildRepo = Mockery::mock(BuildRepository::class);
        $this->deployRepo = Mockery::mock(DeploymentRepository::class);

        $this->em
            ->shouldReceive('getRepository')
            ->with(Build::class)
            ->andReturn($this->buildRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(Deployment::class)
            ->andReturn($this->deployRepo);

        $this->clock = new Clock('now', 'UTC');
        $this->unique = Mockery::mock(JobIdGenerator::class);
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

        $command = new CreateReleaseCommand($this->em, $this->clock, $this->unique);

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

        $this->deployRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $command = new CreateReleaseCommand($this->em, $this->clock, $this->unique);

        $io = $this->io('configureCommand', [
            'BUILD_ID' => '1',
            'TARGET_ID' => '2'
        ]);
        $exit = $command->execute($io);

        $expected = [
            '[ERROR] Deployment target not found. '
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }
}
