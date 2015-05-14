<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use PHPUnit_Framework_TestCase;
use MCP\DataType\Time\Clock;
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
        $this->repoRepo = Mockery::mock('QL\Hal\Core\Repository\RepositoryRepository');
        $this->userRepo = Mockery::mock('QL\Hal\Core\Repository\UserRepository');
        $this->resolver = Mockery::mock('QL\Hal\Agent\Github\ReferenceResolver');
        $this->unique = Mockery::mock('QL\Hal\Core\JobIdGenerator');
        $this->clock = new Clock('now', 'UTC');

        $this->output = new BufferedOutput;
    }

    public function testRepositoryNotFound()
    {
        $this->repoRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $this->input = new ArrayInput([
            'REPOSITORY_ID' => '1',
            'ENVIRONMENT_ID' => '2',
            'GIT_REFERENCE' => '3'
        ]);

        $command = new CreateBuildCommand(
            'derp:cmd',
            $this->em,
            $this->clock,
            $this->buildRepo,
            $this->repoRepo,
            $this->envRepo,
            $this->userRepo,
            $this->resolver,
            $this->unique
        );

        $command->run($this->input, $this->output);

        $expected = [
            'Repository not found.'
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

        $this->input = new ArrayInput([
            'REPOSITORY_ID' => '1',
            'ENVIRONMENT_ID' => '$this->unique',
            'GIT_REFERENCE' => '3'
        ]);

        $command = new CreateBuildCommand(
            'derp:cmd',
            $this->em,
            $this->clock,
            $this->buildRepo,
            $this->repoRepo,
            $this->envRepo,
            $this->userRepo,
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
