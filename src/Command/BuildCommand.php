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
use QL\Hal\Agent\Helper\DownloadProgressHelper;
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
     * @var DownloadProgressHelper
     */
    private $progress;

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
     * @param DownloadProgressHelper $progress
     */
    public function __construct(
        $name,
        LoggerInterface $logger,
        EntityManager $entityManager,
        Clock $clock,
        BuildRepository $buildRepo,
        GithubService $github,
        DownloadProgressHelper $progress
    ) {
        parent::__construct($name);

        $this->logger = $logger;
        $this->entityManager = $entityManager;
        $this->clock = $clock;

        $this->buildRepo = $buildRepo;
        $this->github = $github;
        $this->progress = $progress;
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
            $this->error($output, sprintf(self::ERR_NOT_FOUND, $buildId));
            return 1;
        }

        $output->writeln(sprintf('<info>Found build:</info> %s', $buildId));

        if ($build->getStatus() !== 'Waiting') {
            $this->error($output, sprintf(self::ERR_NOT_WAITING, $buildId, $build->getStatus()));
            return 2;
        }

        $output->writeln(sprintf('<info>Current status:</info> %s', $build->getStatus()));
        $output->writeln(sprintf('<info>Setting status:</info> %s', 'Downloading'));

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

        $context = [
            'build' => [
                'id' => $buildId,
                'path' => $buildPath
            ],
            'github' => $resolvedRepo,
            'commitSha' => $commitSha
        ];
        $output->writeln(sprintf('<info>Build properties:</info> %s', json_encode($context, JSON_PRETTY_PRINT)));
        $this->logger->info('Downloading archive', $context);

        $this->logger->debug('Starting Download', ['time' => $this->clock->read()->format('H:i:s', 'America/Detroit')]);

        $listener = $this->progress->enableDownloadProgress($output);
        if (!$tar = $this->github->download($ghUser, $ghRepo, $commitSha)) {
            $this->error($output, sprintf(self::ERR_DOWNLOAD, $commitSha, $resolvedRepo));
            return 4;
        }

        $this->progress->disableDownloadProgress($listener);

        $this->logger->debug('Finished Download', ['time' => $this->clock->read()->format('H:i:s', 'America/Detroit')]);

        $this->success($output, 'it seemed to work?');
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
    private function success(OutputInterface $output, $message)
    {
        if ($loggerOutput = $this->logger->output()) {
            $output->writeln($loggerOutput);
        }

        $output->writeln(sprintf("\n<question>%s</question>", $message));
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return null
     */
    private function error(OutputInterface $output, $message)
    {
        // $this->logger->critical($message);

        if ($loggerOutput = $this->logger->output()) {
            $output->writeln($loggerOutput);
        }

        $output->writeln(sprintf("\n<error>%s</error>", $message));
    }
}
