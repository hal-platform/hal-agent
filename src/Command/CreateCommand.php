<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Doctrine\ORM\EntityManager;
use MCP\DataType\Time\Clock;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Repository\EnvironmentRepository;
use QL\Hal\Core\Entity\Repository\RepositoryRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *  Create a build
 */
class CreateCommand extends Command
{
    private $entityManager;
    private $clock;
    private $repoRepo;
    private $environmentRepo;

    /**
     * @param string $name
     * @param EntityManager $entityManager
     * @param Clock $clock
     * @param RepositoryRepository $repoRepo
     * @param EnvironmentRepository $environmentRepo
     */
    public function __construct(
        $name,
        EntityManager $entityManager,
        Clock $clock,
        RepositoryRepository $repoRepo,
        EnvironmentRepository $environmentRepo
    ) {
        parent::__construct($name);

        $this->entityManager = $entityManager;
        $this->clock = $clock;
        $this->repoRepo = $repoRepo;
        $this->environmentRepo = $environmentRepo;
    }

    /**
     *  Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Deploy a previously built application to a server.')
            ->addArgument(
                'REPOSITORY_ID',
                InputArgument::REQUIRED,
                'The ID of the repository to build.'
            )
            ->addArgument(
                'ENVIRONMENT_ID',
                InputArgument::REQUIRED,
                'The ID of the environment to build for.'
            )
            ->addArgument(
                'GIT_REF',
                InputArgument::REQUIRED,
                'The git reference to build.'
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
        $repositoryId = $input->getArgument('REPOSITORY_ID');
        $environmentId = $input->getArgument('ENVIRONMENT_ID');
        $reference = $input->getArgument('GIT_REF');

        if (!$repository = $this->repoRepo->find($repositoryId)) {
            $output->writeln(sprintf('<error>Repository ID "%s" not found.</error>', $repositoryId));
            return 1;
        }

        if (!$environment = $this->environmentRepo->find($environmentId)) {
            $output->writeln(sprintf('<error>Environment ID "%s" not found.</error>', $environmentId));
            return 2;
        }

        // need to validate the ref

        $build = new Build;
        $build->setId($this->generateBuildId());
        $build->setStatus('Waiting');
        $build->setRepository($repository);
        $build->setEnvironment($environment);
        $build->setCommit($reference);
        $build->setBranch('dontcare');

        $this->entityManager->persist($build);
        $this->entityManager->flush();

        $id = $build->getId();
        $output->writeln(sprintf('<question>Build created: %s</question>', $id));
    }

    /**
     * @return string
     */
    private function generateBuildId()
    {
        return sha1(microtime(true) . mt_rand(10000,90000));
    }
}
