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
// use QL\Hal\Agent\Build\Builder;
use QL\Hal\Agent\Build\Downloader;
use QL\Hal\Agent\Build\Packer;
use QL\Hal\Agent\Build\Resolver;
use QL\Hal\Agent\Build\Unpacker;
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
     * @var Unpacker
     */
    private $unpacker;

    /**
     * @var Builder
     */
    // private $builder;

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
     * @param string $name
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
        LoggerInterface $logger,
        EntityManager $entityManager,
        Clock $clock,
        Resolver $resolver,
        Downloader $downloader,
        Unpacker $unpacker,
        // Builder $builder,
        Packer $packer,
        DownloadProgressHelper $progress
    ) {
        parent::__construct($name);

        $this->logger = $logger;
        $this->entityManager = $entityManager;
        $this->clock = $clock;

        $this->resolver = $resolver;
        $this->downloader = $downloader;
        $this->unpacker = $unpacker;
        // $this->builder = $builder;
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
        $output->writeln('<comment>Resolving...</comment>');
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

        // add artifacts for cleanup
        $this->artifacts = array_merge($this->artifacts, [$properties['archiveFile'], $properties['buildPath']]);

        // download
        $downloadProperties = [
            $properties['githubUser'],
            $properties['githubRepo'],
            $properties['githubReference'],
            $properties['archiveFile']
        ];

        $output->writeln('<comment>Downloading...</comment>');
        $this->progress->enableDownloadProgress($output);
        if (!call_user_func_array($this->downloader, $downloadProperties)) {
            $this->error($output, 'Repository archive could not be downloaded.');
            return 2;
        }

        // unpack
        $output->writeln('<comment>Unpacking...</comment>');
        if (!call_user_func($this->unpacker, $properties['archiveFile'], $properties['buildPath'])) {
            $this->error($output, 'Repository archive could not be unpacked.');
            return 4;
        }

        // building goes here
        $output->writeln('<comment>Building...</comment>');
        $output->writeln('<info>noop</info>');

        // pack
        $output->writeln('<comment>Packing...</comment>');
        if (!call_user_func($this->packer, $properties['buildPath'], $properties['buildFile'])) {
            $this->error($output, 'Build archive could not be packed.');
            return 8;
        }

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
