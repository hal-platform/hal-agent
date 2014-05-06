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
use Symfony\Component\Process\ProcessBuilder;

/**
 *  Build an application for a particular environment.
 *
 * The amount of dependencies of this command is too damn high.
 */
class BuildCommand extends Command
{
    use CommandTrait;
    use FormatterTrait;

    /**
     * A list of all possible exit codes of this command
     *
     * @var array
     */
    private static $codes = [
        0 => 'Success!',
        1 => 'Build details could not be resolved.',
        2 => 'Repository archive could not be downloaded.',
        4 => 'Repository archive could not be unpacked.',
        8 => 'Build command failed.',
        16 => 'Build archive could not be created.'
    ];

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
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var string[]
     */
    private $artifacts;

    /**
     * @var Build|null
     */
    private $build;

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
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(
        $name,
        LoggerInterface $logger,
        EntityManager $entityManager,
        Clock $clock,
        Resolver $resolver,
        Downloader $downloader,
        Unpacker $unpacker,
        Builder $builder,
        Packer $packer,
        DownloadProgressHelper $progress,
        ProcessBuilder $processBuilder
    ) {
        parent::__construct($name);

        $this->logger = $logger;
        $this->entityManager = $entityManager;
        $this->clock = $clock;

        $this->resolver = $resolver;
        $this->downloader = $downloader;
        $this->unpacker = $unpacker;
        $this->builder = $builder;
        $this->packer = $packer;

        $this->progress = $progress;
        $this->processBuilder = $processBuilder;

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

        $errors = ['Exit Codes:'];
        foreach (static::$codes as $code => $message) {
            $errors[] = $this->formatSection($code, $message);
        }
        $this->setHelp(implode("\n", $errors));
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

        if (!$properties = $this->resolve($output, $buildId)) {
            return $this->failure($output, 1);
        }

        $this->prepare($output, $properties);

        if (!$this->download($output, $properties)) {
            return $this->failure($output, 2);
        }

        if (!$this->unpack($output, $properties)) {
            return $this->failure($output, 4);
        }

        if (!$this->build($output, $properties)) {
            return $this->failure($output, 8);
        }

        if (!$this->pack($output, $properties)) {
            return $this->failure($output, 16);
        }

        $this->success($output);
    }

    /**
     * @return null
     */
    private function cleanup()
    {
        $this->processBuilder->setPrefix(['rm', '-rf']);

        foreach ($this->artifacts as $path) {
            $process = $this->processBuilder
                ->setWorkingDirectory(null)
                ->setArguments([$path])
                ->getProcess();

            $process->run();
        }
    }

    /**
     * @param OutputInterface $output
     * @param int $exitCode
     * @return null
     */
    private function finish(OutputInterface $output, $exitCode)
    {
        if ($this->build) {
            $status = ($exitCode === 0) ? 'Success' : 'Error';
            $this->build->setStatus($status);

            $this->build->setEnd($this->clock->read());
            $this->entityManager->merge($this->build);
            $this->entityManager->flush();
        }

        // verbosity = 1: Output log messages
        // verbosity = 2: Output log context
        if ($output->isVerbose() && $loggerOutput = $this->logger->output($output->isVeryVerbose())) {
            $output->writeln($loggerOutput);
        }

        $this->cleanup();

        return $exitCode;
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
     * @var array $context
     * @return array
     */
    private function timer(array $context = [])
    {
        return array_merge(
            $context,
            ['time' => $this->clock->read()->format('c', 'America/Detroit')]
        );
    }

    /**
     * @param OutputInterface $output
     * @param string $buildId
     * @return array|null
     */
    private function resolve(OutputInterface $output, $buildId)
    {
        $output->writeln('<comment>Resolving...</comment>');
        return call_user_func($this->resolver, $buildId);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return null
     */
    private function prepare(OutputInterface $output, array $properties)
    {
        $this->build = $properties['build'];
        $output->writeln(sprintf('<info>Build properties:</info> %s', json_encode($properties, JSON_PRETTY_PRINT)));

        // Update the build status asap so no other worker can pick it up
        $this->setEntityStatus('Building', true);

        // add artifacts for cleanup
        $this->artifacts = array_merge($this->artifacts, [$properties['buildFile'], $properties['buildPath']]);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function download(OutputInterface $output, array $properties)
    {
        $this->logger->debug('Download started', $this->timer());
        $output->writeln('<comment>Downloading...</comment>');

        $this->progress->enableDownloadProgress($output);

        $success = call_user_func_array($this->downloader, [
            $properties['githubUser'],
            $properties['githubRepo'],
            $properties['githubReference'],
            $properties['buildFile']
        ]);

        if (!$success) {
            return false;
        }

        $this->logger->debug('Download finished', $this->timer());
        return true;
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function unpack(OutputInterface $output, array $properties)
    {
        $output->writeln('<comment>Unpacking...</comment>');
        return call_user_func(
            $this->unpacker,
            $properties['buildFile'],
            $properties['buildPath']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function build(OutputInterface $output, array $properties)
    {
        if (!$properties['buildCommand']) {
            return true;
        }

        $this->logger->debug('Building started', $this->timer());
        $output->writeln('<comment>Building...</comment>');

        $success = call_user_func_array($this->builder, [
            $properties['buildPath'],
            $properties['buildCommand'],
            $properties['environmentVariables']
        ]);

        if (!$success) {
            return false;
        }

        $this->logger->debug('Building finished', $this->timer());
        return true;
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function pack(OutputInterface $output, array $properties)
    {
        $output->writeln('<comment>Packing...</comment>');
        return call_user_func(
            $this->packer,
            $properties['buildPath'],
            $properties['archiveFile']
        );
    }
}
