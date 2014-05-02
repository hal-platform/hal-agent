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
use QL\Hal\Agent\Build\Builder;
use QL\Hal\Agent\Build\Downloader;
use QL\Hal\Agent\Build\Packer;
use QL\Hal\Agent\Build\Resolver;
use QL\Hal\Agent\Build\Unpacker;
use QL\Hal\Agent\Helper\DownloadProgressHelper;
use QL\Hal\Core\Entity\Build;
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
     * @var Unpacker
     */
    private $unpacker;

    /**
     * @var Builder
     */
    private $builder;

    /**
     * @var Packer
     */
    private $packer;

    /**
     * @var DownloadProgressHelper
     */
    private $progress;

    /**
     * @var string[]
     */
    private $artifacts;

    /**
     * @var boolean
     */
    private $debugMode;

    /**
     * @var Build|null
     */
    private $build;

    /**
     * @param string $name
     * @param boolean $debugMode
     * @param LoggerInterface $logger
     * @param EntityManager $entityManager
     * @param Clock $clock
     * @param Resolver $resolver
     * @param Downloader $downloader
     * @param Builder $builder
     * @param Packer $packer
     * @param DownloadProgressHelper $progress
     */
    public function __construct(
        $name,
        $debugMode,
        LoggerInterface $logger,
        EntityManager $entityManager,
        Clock $clock,
        Resolver $resolver,
        Downloader $downloader,
        Unpacker $unpacker,
        Builder $builder,
        Packer $packer,
        DownloadProgressHelper $progress
    ) {
        parent::__construct($name);

        $this->debugMode = $debugMode;
        $this->logger = $logger;
        $this->entityManager = $entityManager;
        $this->clock = $clock;

        $this->resolver = $resolver;
        $this->downloader = $downloader;
        $this->unpacker = $unpacker;
        $this->builder = $builder;
        $this->packer = $packer;

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

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return null
     */
    private function error(OutputInterface $output, $message)
    {
        if ($this->build) {
            $this->build->setStatus('Error');
        }

        if ($this->debugMode && $loggerOutput = $this->logger->output(true)) {
            $output->writeln($loggerOutput);
        }

        $this->finish($output);
        $output->writeln(sprintf("<error>%s</error>", $message));
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
        // expected build statuses
        // Waiting, Downloading, Building, Finished, Error

        $buildId = $input->getArgument('BUILD_ID');

        // resolve
        $output->writeln('<comment>Resolving...</comment>');
        if (!$properties = call_user_func($this->resolver, $buildId)) {
            $this->error($output, 'Build details could not be resolved.');
            return 1;
        }

        $this->build = $properties['build'];

        // Update the build status asap so no other worker can pick it up
        $this->setEntityStatus('Building', true);

        $output->writeln(sprintf('<info>Build properties:</info> %s', json_encode($properties, JSON_PRETTY_PRINT)));

        // add artifacts for cleanup
        $this->artifacts = array_merge($this->artifacts, [$properties['archiveFile'], $properties['buildPath']]);

        // download
        $downloadProperties = [
            $properties['githubUser'],
            $properties['githubRepo'],
            $properties['githubReference'],
            $properties['archiveFile']
        ];

        $this->logger->debug('Download started', $this->timer());
        $output->writeln('<comment>Downloading...</comment>');
        $this->progress->enableDownloadProgress($output);
        if (!call_user_func_array($this->downloader, $downloadProperties)) {
            $this->error($output, 'Repository archive could not be downloaded.');
            return 2;
        }
        $this->logger->debug('Download finished', $this->timer());

        // unpack
        $output->writeln('<comment>Unpacking...</comment>');
        if (!call_user_func($this->unpacker, $properties['archiveFile'], $properties['buildPath'])) {
            $this->error($output, 'Repository archive could not be unpacked.');
            return 4;
        }

        // build
        if (!$properties['buildCommand']) {
            goto SKIP_BUILDING;
        }

        $buildProperties = [
            $properties['buildPath'],
            $properties['buildCommand'],
            $properties['environmentVariables']
        ];

        $this->logger->debug('Building started', $this->timer());
        $output->writeln('<comment>Building...</comment>');
        if (!call_user_func_array($this->builder, $buildProperties)) {
            $this->error($output, 'Build command failed.');
            return 8;
        }
        $this->logger->debug('Building finished', $this->timer());

        SKIP_BUILDING:

        // pack
        $output->writeln('<comment>Packing...</comment>');
        if (!call_user_func($this->packer, $properties['buildPath'], $properties['buildFile'])) {
            $this->error($output, 'Build archive could not be created.');
            return 16;
        }

        $this->success($output);
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return null
     */
    private function finish(OutputInterface $output)
    {
        if ($this->build) {
            $this->build->setEnd($this->clock->read());
            $this->entityManager->merge($this->build);
            $this->entityManager->flush();
        }

        $this->cleanup();
    }

    /**
     * @param string $status
     * @param boolean $start
     * @return null
     */
    private function setEntityStatus($status, $start = false)
    {
        if (!$this->build) {
            return;
        }

        $this->build->setStatus($status);
        if ($start) {
            $this->build->setStart($this->clock->read());
        }

        $this->entityManager->merge($this->build);
        $this->entityManager->flush();
    }

    /**
     * @param OutputInterface $output
     * @return null
     */
    private function success(OutputInterface $output)
    {
        if ($this->build) {
            $this->build->setStatus('Success');
        }

        $this->finish($output);
        $output->writeln(sprintf("<question>%s</question>", 'Success!'));
    }

    /**
     * @var array $context
     * @return array
     */
    private function timer(array $context = [])
    {
        return array_merge(
            $context,
            ['time' => $this->clock->read()->format('H:i:s', 'America/Detroit')]
        );
    }
}
