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
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\TableHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ListBuildsCommandTest extends PHPUnit_Framework_TestCase
{
    public $buildRepo;
    public $repoRepo;
    public $envRepo;
    public $filesystem;
    public $archive;

    public $input;
    public $output;

    public function setUp()
    {
        $this->buildRepo = Mockery::mock('QL\Hal\Core\Repository\BuildRepository');
        $this->repoRepo = Mockery::mock('QL\Hal\Core\Repository\RepositoryRepository');
        $this->envRepo = Mockery::mock('QL\Hal\Core\Repository\EnvironmentRepository');
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
            $this->buildRepo,
            $this->repoRepo,
            $this->envRepo,
            $this->filesystem,
            $this->archive
        );

        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
No builds found.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
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
            $this->buildRepo,
            $this->repoRepo,
            $this->envRepo,
            $this->filesystem,
            $this->archive
        );
        $command->setHelperSet(new HelperSet([
            new TableHelper
        ]));

        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Displaying 1 - 2 out of 2: 
+---------+---------------------------+------+------------+-------------+--------------------------+
| Status  | Created Time              | Id   | Repository | Environment | Archive                  |
+---------+---------------------------+------+------------+-------------+--------------------------+
| Success | 2015-03-15T00:05:06-04:00 | 1234 | repo-name  | env-name    | path/hal9000-1234.tar.gz |
| Waiting |                           | 5678 | repo-name  | env-name    |                          |
+---------+---------------------------+------+------------+-------------+--------------------------+

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }
}
