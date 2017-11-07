<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Management;

use Doctrine\ORM\EntityManager;
use Hal\Agent\Github\ReferenceResolver;
use Hal\Agent\Testing\ExecutorTestCase;
use Mockery;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Environment;
use Hal\Core\Repository\EnvironmentRepository;
use Hal\Core\Repository\ApplicationRepository;
use QL\MCP\Common\Time\Clock;

class CreateBuildCommandTest extends ExecutorTestCase
{
    public $em;
    public $envRepo;
    public $repoRepo;
    public $resolver;
    public $unique;
    public $clock;

    public function setUp()
    {
        $this->em = Mockery::mock(EntityManager::class);
        $this->envRepo = Mockery::mock(EnvironmentRepository::class);
        $this->appRepo = Mockery::mock(ApplicationRepository::class);

        $this->em
            ->shouldReceive('getRepository')
            ->with(Environment::class)
            ->andReturn($this->envRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(Application::class)
            ->andReturn($this->appRepo);

        $this->resolver = Mockery::mock(ReferenceResolver::class);
        $this->clock = new Clock('now', 'UTC');
    }

    public function configureCommand($c)
    {
        CreateBuildCommand::configure($c);
    }

    public function testApplicationNotFound()
    {
        $this->appRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $this->appRepo
            ->shouldReceive('findOneBy')
            ->andReturnNull();

        $command = new CreateBuildCommand($this->em, $this->clock, $this->resolver);

        $io = $this->io('configureCommand', [
            'APPLICATION' => '1',
            'ENVIRONMENT' => '2',
            'GIT_REFERENCE' => '3'
        ]);
        $exit = $command->execute($io);

        $expected = [
            '[ERROR] Application not found.'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }

    public function testEnvironmentNotFound()
    {
        $this->appRepo
            ->shouldReceive('find')
            ->andReturn('1');

        $this->envRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $this->envRepo
            ->shouldReceive('findOneBy')
            ->andReturnNull();

        $command = new CreateBuildCommand($this->em, $this->clock, $this->resolver);

        $io = $this->io('configureCommand', [
            'APPLICATION' => '1',
            'ENVIRONMENT' => '2',
            'GIT_REFERENCE' => '3'
        ]);
        $exit = $command->execute($io);

        $expected = [
            '[ERROR] Environment not found.'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }
}
