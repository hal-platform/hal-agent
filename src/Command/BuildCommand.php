<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Doctrine\ORM\EntityManager;
use MCP\DataType\Time\Clock;
use Psr\Log\LoggerInterface;
use QL\Hal\Agent\Github\GithubService;
use QL\Hal\Core\Entity\Repository\BuildRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *  Build an application for a particular environment.
 */
class BuildCommand extends Command
{
    /**
     * @var string
     */
    const FS_DIRECTORY_PREFIX = 'hal9000-build-';

    const ERR_NOT_FOUND = 'Build "%s" could not be found!';
    const ERR_NOT_WAITING = 'Build "%s" has a status of "%s"! It cannot be rebuilt. Or can it?';
    const ERR_DOWNLOAD = 'Github reference "%s" from repository "%s" could not be downloaded!';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var BuildRepository
     */
    private $buildRepo;

    /**
     * @var GithubService
     */
    private $github;

    /**
     * @var string
     */
    private $buildDirectory;

    /**
     * @param string $name
     * @param LoggerInterface $logger
     * @param EntityManager $entityManager
     * @param Clock $clock
     * @param BuildRepository $buildRepo
     * @param GithubService $github
     */
    public function __construct(
        $name,
        LoggerInterface $logger,
        EntityManager $entityManager,
        Clock $clock,
        BuildRepository $buildRepo,
        GithubService $github
    ) {
        parent::__construct($name);

        $this->logger = $logger;
        $this->entityManager = $entityManager;
        $this->clock = $clock;

        $this->buildRepo = $buildRepo;
        $this->github = $github;
    }

    /**
     * @param string $directory
     *  @return null
     */
    public function setBaseBuildDirectory($directory)
    {
        $this->buildDirectory = $directory;
    }

    /**
     *  Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Build an application build.')
            ->addArgument(
                'BUILD_ID',
                InputArgument::REQUIRED,
                'The Build ID to build.'
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
        $buildId = $input->getArgument('BUILD_ID');

        $build = $this->buildRepo->find($buildId);
        if (!$build = $this->buildRepo->find($buildId)) {
            $this->logger->critical(sprintf(self::ERR_NOT_FOUND, $buildId));
            $this->finish($output, '<error>FAIL 1</error>');
            return 1;
        }

        if ($build->getStatus() !== 'Waiting') {
            $this->logger->critical(sprintf(self::ERR_NOT_WAITING, $buildId, $build->getStatus()));
            $this->finish($output, '<error>FAIL 2</error>');
            return 2;
        }

        // Update the build status asap so no other worker can pick it up
        $build->setStatus('Downloading');
        $this->entityManager->merge($build);
        $this->entityManager->flush();

        // build properties
        $buildPath = $this->generateBuildDirectory($buildId);

        $ghUser = $build->getRepository()->getGithubUser();
        $ghRepo = $build->getRepository()->getGithubRepo();
        $commitSha = $build->getCommit();
        $resolvedRepo = sprintf('%s/%s', $ghUser, $ghRepo);

        $this->logger->info('Downloading archive', [
            'build' => [
                'id' => $buildId,
                'path' => $buildPath
            ],
            'github' => $resolvedRepo,
            'commitSha' => $commitSha
        ]);


        $this->logger->debug('Starting Download', ['time' => $this->clock->read()->format('H:i:s', 'America/Detroit')]);

        if (!$tar = $this->github->download($ghUser, $ghRepo, $commitSha)) {
            $this->logger->critical(sprintf(self::ERR_DOWNLOAD, $commitSha, $resolvedRepo));
            $this->finish($output, '<error>FAIL 4</error>');
            return 4;
        }

        $this->logger->debug('Finished Download', ['time' => $this->clock->read()->format('H:i:s', 'America/Detroit')]);

        $this->finish($output, '<question>it seemed to work?</question>');
    }

    /**
     *  Generate, but don't create, a random directory for later use
     *
     *  @param string $id
     *  @return string
     */
    private function generateBuildDirectory($id)
    {
        return $this->getBuildDirectory() . self::FS_DIRECTORY_PREFIX . substr($id, 0, 7);
    }

    /**
     *  @param string $id
     *  @return string
     */
    private function getBuildDirectory()
    {
        if (!$this->buildDirectory) {
            $this->buildDirectory = sys_get_temp_dir();
        }

        return rtrim($this->buildDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return null
     */
    private function finish(OutputInterface $output, $message)
    {
        $output->writeln($this->logger->output());
        $output->writeln("\n". $message);
    }
}
