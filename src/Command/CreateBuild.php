<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Github\Client as GithubService;
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
     * @var GithubService
     */
    private $github;

    /**
     * @param string $name
     * @param EnvironmentRepository $envRepo
     * @param RepositoryRepository $repoRepo
     * @param GithubService $github
     */
    public function __construct(
        $name,
        EnvironmentRepository $envRepo,
        RepositoryRepository $repoRepo,
        GithubService $github
    ) {
        parent::__construct($name);

        $this->envRepo = $envRepo;
        $this->repoRepo = $repoRepo;
        $this->github = $github;
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
        $formatter = $this->getHelperSet()->get('formatter');

        $output->writeln([
            $formatter->formatSection('Environment ID', $environmentId),
            $formatter->formatSection('Repository ID', $repositoryId),
            $formatter->formatSection('Commit', $commitSha)
        ]);

        if (!$environment = $this->envRepo->find($environmentId)) {
            $output->writeln('<error>Environment not found!</error>');
            return 1;
        }
        $output->writeln('<comment>Environment Found!</comment>');

        if (!$repository = $this->repoRepo->find($repositoryId)) {
            $output->writeln('<error>Repository not found!</error>');
            return 2;
        }
        $output->writeln('<comment>Repository Found!</comment>');


        $resolved = sprintf('%s/%s', $repository->getGithubUser(), $repository->getGithubRepo());
        $output->writeln($formatter->formatSection('Environment', $environment->getKey()));
        $output->writeln($formatter->formatSection('Repository', $repository->getKey()));
        $output->writeln($formatter->formatSection('Github Repository', $resolved));


        // download through api
        // /repos/:owner/:repo/:archive_format/:ref

        $output->writeln("\n<question>it seemed to work?</question>");
    }
}
