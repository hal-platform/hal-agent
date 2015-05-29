<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use MCP\DataType\Time\TimePoint;
use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Repository;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ListBuildsCommandTest extends PHPUnit_Framework_TestCase
{
    public $em;
    public $buildRepo;
    public $repoRepo;
    public $envRepo;

    public $filesystem;
    public $archive;

    public $input;
    public $output;

    public function setUp()
    {
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager');
        $this->buildRepo = Mockery::mock('QL\Hal\Core\Repository\BuildRepository');
        $this->repoRepo = Mockery::mock('Doctrine\ORM\EntityRepository');
        $this->envRepo = Mockery::mock('QL\Hal\Core\Repository\EnvironmentRepository');

        $this->em
            ->shouldReceive('getRepository')
            ->with(Build::CLASS)
            ->andReturn($this->buildRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(Repository::CLASS)
            ->andReturn($this->repoRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(Environment::CLASS)
            ->andReturn($this->envRepo);


        $this->filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');
        $this->archive = 'path';

        $this->output = new BufferedOutput;
    }

    public function testBuildsNotFound()
    {
        $this->buildRepo
            ->shouldReceive('matching')
            ->andReturn([]);

        $this->input = new ArrayInput([]);

        $command = new ListBuildsCommand(
            'derp:cmd',
            $this->em,
            $this->filesystem,
            $this->archive
        );

        $command->run($this->input, $this->output);

        $expected = [
            'No builds found.'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }

    public function testBuildTableOutput()
    {
        $environment = Mockery::mock('QL\Hal\Core\Entity\Environment', ['getKey' => 'env-name']);
        $repository = Mockery::mock('QL\Hal\Core\Entity\Repository', ['getKey' => 'repo-name']);

        $build1 = new Build;
        $build1->setRepository($repository);
        $build1->setEnvironment($environment);
        $build1->setId('1234');
        $build1->setStatus('Success');
        $build1->setCreated(new TimePoint(2015, 3, 15, 4, 5, 6, 'UTC'));

        $build2 = new Build;
        $build2->setRepository($repository);
        $build2->setEnvironment($environment);
        $build2->setId('5678');
        $build2->setStatus('Waiting');

        $this->buildRepo
            ->shouldReceive('matching')
            ->andReturn([$build1, $build2]);

        $this->input = new ArrayInput([]);

        $command = new ListBuildsCommand(
            'derp:cmd',
            $this->em,
            $this->filesystem,
            $this->archive
        );

        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Displaying 1 - 2 out of 2: 
+---------+---------------------+------+------------+-------------+--------------------------+
| Status  | Created Time        | Id   | Repository | Environment | Archive                  |
+---------+---------------------+------+------------+-------------+--------------------------+
| Success | 2015-03-15 00:05:06 | 1234 | repo-name  | env-name    | path/hal9000-1234.tar.gz |
| Waiting |                     | 5678 | repo-name  | env-name    |                          |
+---------+---------------------+------+------------+-------------+--------------------------+

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }
}
