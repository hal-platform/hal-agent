<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Command;

use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\User;
use QL\MCP\Common\Time\Clock;
use PHPUnit_Framework_TestCase;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CreateBuildCommandTest extends PHPUnit_Framework_TestCase
{
    public $em;
    public $buildRepo;
    public $envRepo;
    public $repoRepo;
    public $userRepo;
    public $resolver;
    public $unique;
    public $clock;

    public $input;
    public $output;

    public function setUp()
    {
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager');
        $this->buildRepo = Mockery::mock('QL\Hal\Core\Repository\BuildRepository');
        $this->envRepo = Mockery::mock('QL\Hal\Core\Repository\EnvironmentRepository');
        $this->repoRepo = Mockery::mock('Doctrine\ORM\EntityRepository');
        $this->userRepo = Mockery::mock('QL\Hal\Core\Repository\UserRepository');

        $this->em
            ->shouldReceive('getRepository')
            ->with(Build::CLASS)
            ->andReturn($this->buildRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(Environment::CLASS)
            ->andReturn($this->envRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(Application::CLASS)
            ->andReturn($this->repoRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(User::CLASS)
            ->andReturn($this->userRepo);


        $this->resolver = Mockery::mock('QL\Hal\Agent\Github\ReferenceResolver');
        $this->unique = Mockery::mock('QL\Hal\Core\JobIdGenerator');
        $this->clock = new Clock('now', 'UTC');

        $this->output = new BufferedOutput;
    }

    public function testApplicationNotFound()
    {
        $this->repoRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $this->repoRepo
            ->shouldReceive('findOneBy')
            ->andReturnNull();

        $this->input = new ArrayInput([
            'APPLICATION' => '1',
            'ENVIRONMENT' => '2',
            'GIT_REFERENCE' => '3'
        ]);

        $command = new CreateBuildCommand(
            'derp:cmd',
            $this->em,
            $this->clock,
            $this->resolver,
            $this->unique
        );

        $command->run($this->input, $this->output);

        $expected = [
            'Application not found.'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }

    public function testEnvironmentNotFound()
    {
        $this->repoRepo
            ->shouldReceive('find')
            ->andReturn('1');

        $this->envRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $this->envRepo
            ->shouldReceive('findOneBy')
            ->andReturnNull();

        $this->input = new ArrayInput([
            'APPLICATION' => '1',
            'ENVIRONMENT' => '2',
            'GIT_REFERENCE' => '3'
        ]);

        $command = new CreateBuildCommand(
            'derp:cmd',
            $this->em,
            $this->clock,
            $this->resolver,
            $this->unique
        );

        $command->run($this->input, $this->output);

        $expected = [
            'Environment not found.'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }
}
