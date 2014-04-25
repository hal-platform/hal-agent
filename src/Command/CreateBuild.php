<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use QL\Hal\Core\Entity\Repository\EnvironmentRepository;
use QL\Hal\Core\Entity\Repository\RepositoryRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *  Build an application for a particular environment.
 */
class CreateBuild extends Command
{
    /**
     * @var EnvironmentRepository
     */
    private $envRepo;

    /**
     * @var RepositoryRepository
     */
    private $repoRepo;

    /**
     * @param string $name
     * @param EnvironmentRepository $envRepo
     * @param RepositoryRepository $repoRepo
     */
    public function __construct($name, EnvironmentRepository $envRepo, RepositoryRepository $repoRepo)
    {
        parent::__construct($name);

        $this->envRepo = $envRepo;
        $this->repoRepo = $repoRepo;
    }

    /**
     *  Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Build an application and return the build ID.')
            ->addArgument(
                'ENV_ID',
                InputArgument::REQUIRED,
                'The environment ID to build for.'
            )
            ->addArgument(
                'REPO_ID',
                InputArgument::REQUIRED,
                'The repository ID to build.'
            )
            ->addArgument(
                'COMMIT',
                InputArgument::REQUIRED,
                'The commit hash to build.'
            );
    }

    /**
     *  Run the command
     *
     *  @param InputInterface $input
     *  @param OutputInterface $output
     *  @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $environmentId = $input->getArgument('ENV_ID');
        $repositoryId = $input->getArgument('REPO_ID');
        $commitSha = $input->getArgument('COMMIT');




        $output->writeln('NYI1');
    }
}
