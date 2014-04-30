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
use QL\Hal\Agent\Build\Downloader;
use QL\Hal\Agent\Build\Resolver;
use QL\Hal\Agent\Helper\DownloadProgressHelper;
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
     * @var Resolver
     */
    private $resolver;

    /**
     * @var Downloader
     */
    private $downloader;

    /**
     * @var DownloadProgressHelper
     */
    private $progress;

    /**
     * @var string[]
     */
    private $artifacts;

    /**
     * @param string $name
     * @param LoggerInterface $logger
     * @param EntityManager $entityManager
     * @param Clock $clock
     * @param Resolver $resolver
     * @param Downloader $downloader
     * @param DownloadProgressHelper $progress
     */
    public function __construct(
        $name,
        LoggerInterface $logger,
        EntityManager $entityManager,
        Clock $clock,
        Resolver $resolver,
        Downloader $downloader,
        DownloadProgressHelper $progress
    ) {
        parent::__construct($name);

        $this->logger = $logger;
        $this->entityManager = $entityManager;
        $this->clock = $clock;

        $this->resolver = $resolver;
        $this->downloader = $downloader;

        $this->progress = $progress;

        $this->artifacts = [];
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

        // resolve
        if (!$properties = call_user_func($this->resolver, $buildId)) {
            $this->error($output, 'Build details could not be resolved.');
            return 1;
        }

        $output->writeln(sprintf('<info>Setting status:</info> %s', 'Downloading'));

        // Update the build status asap so no other worker can pick it up
        // $build = $properties['build'];
        // $build->setStatus('Downloading');
        // $this->entityManager->merge($build);
        // $this->entityManager->flush();

        $output->writeln(sprintf('<info>Build properties:</info> %s', json_encode($properties, JSON_PRETTY_PRINT)));
        $this->logger->info('Downloading archive', $properties);
        $output->writeln(sprintf('<info>Archive Target:</info> %s', $properties['buildArchive']));

        $this->logger->debug('Starting Download', ['time' => $this->clock->read()->format('H:i:s', 'America/Detroit')]);

        // add artifacts for cleanup
        $this->artifacts = array_merge($this->artifacts, [$properties['buildArchive'], $properties['buildPath']]);

        // download
        $downloadProperties = [
            $properties['githubUser'],
            $properties['githubRepo'],
            $properties['githubReference'],
            $properties['buildArchive']
        ];

        $this->progress->enableDownloadProgress($output);
        if (!$tar = call_user_func_array($this->downloader, $downloadProperties)) {
            $this->error('Repository archive could not be downloaded.');
            return 2;
        }

        $this->logger->debug('Finished Download', [
            'time' => $this->clock->read()->format('H:i:s', 'America/Detroit')
        ]);

        // unpack
        // $this->unpack($archiveTarget, $buildPath);


        $this->success($output);
    }


    /**
     * @param OutputInterface $output
     * @return null
     */
    private function success(OutputInterface $output)
    {
        $this->finish($output);

        $message = 'it seemed to work?';
        $output->writeln(sprintf("<question>%s</question>", $message));
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return null
     */
    private function error(OutputInterface $output, $message)
    {
        // $this->logger->critical($message);

        $this->finish($output);
        $output->writeln(sprintf("<error>%s</error>", $message));
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return null
     */
    private function finish(OutputInterface $output)
    {
        if ($loggerOutput = $this->logger->output()) {
            // $output->writeln($loggerOutput);
        }

        $this->cleanup();
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return null
     */
    private function cleanup()
    {
        foreach ($this->artifacts as $path) {
            exec(sprintf('rm -rf %s*', escapeshellarg($path)));
        }
    }
}
