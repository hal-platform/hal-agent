<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use QL\Hal\Agent\Build\Builder;
use QL\Hal\Agent\Build\Downloader;
use QL\Hal\Agent\Build\Packer;
use QL\Hal\Agent\Build\Resolver;
use QL\Hal\Agent\Build\Unpacker;
use QL\Hal\Agent\Helper\DownloadProgressHelper;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Core\Entity\Build;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Build an application for a particular environment.
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
     * @var EventLogger
     */
    private $logger;

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
     * @var boolean
     */
    private $enableShutdownHandler;

    /**
     * @param string $name
     * @param EventLogger $logger
     * @param Resolver $resolver
     * @param Downloader $downloader
     * @param Builder $builder
     * @param Packer $packer
     * @param DownloadProgressHelper $progress
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(
        $name,
        EventLogger $logger,
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

        $this->resolver = $resolver;
        $this->downloader = $downloader;
        $this->unpacker = $unpacker;
        $this->builder = $builder;
        $this->packer = $packer;

        $this->progress = $progress;
        $this->processBuilder = $processBuilder;

        $this->artifacts = [];

        $this->enableShutdownHandler = true;
    }

    /**
     * In case of error or critical failure, ensure that we clean up the build artifacts.
     *
     * Note that this is only called for exceptions and non-fatal errors.
     * Fatal errors WILL NOT trigger this.
     *
     * @return null
     */
    public function __destruct()
    {
        $this->blowTheHatch();
    }

    /**
     * @return null
     */
    public function disableShutdownHandler()
    {
        $this->enableShutdownHandler = false;
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

        $help = ['<fg=cyan>Exit codes:</fg=cyan>'];
        foreach (static::$codes as $code => $message) {
            $help[] = $this->formatSection($code, $message);
        }
        $this->setHelp(implode("\n", $help));
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
        // Waiting, Building, Success, Error, Removed

        $buildId = $input->getArgument('BUILD_ID');

        // Add subscriptions
        $this->logger->addSubscription('build.success', 'notifier.email');
        $this->logger->addSubscription('build.failure', 'notifier.email');

        $this->logger->setStage('build.start');

        if (!$properties = $this->resolve($output, $buildId)) {
            return $this->failure($output, 1);
        }

        // Set the build to in progress
        $this->prepare($output, $properties);

        if (!$this->download($output, $properties)) {
            return $this->failure($output, 2);
        }

        if (!$this->unpack($output, $properties)) {
            return $this->failure($output, 4);
        }

        $this->logger->setStage('building');

        if (!$this->build($output, $properties)) {
            return $this->failure($output, 8);
        }

        $this->logger->setStage('end');

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

        $poppers = 0;
        while ($this->artifacts && $poppers < 10) {
            # while loops make me paranoid, ok?
            $poppers++;

            $path = array_pop($this->artifacts);
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
        if ($exitCode === 0) {
            $this->logger->success();
        } else {
            $this->logger->failure();
        }

        $this->cleanup();

        return $exitCode;
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return null
     */
    private function status(OutputInterface $output, $message)
    {
        $message = sprintf('<comment>%s</comment>', $message);
        $output->writeln($message);
    }

    /**
     * @param OutputInterface $output
     * @param string $buildId
     * @return array|null
     */
    private function resolve(OutputInterface $output, $buildId)
    {
        $this->status($output, 'Resolving build properties');
        return call_user_func($this->resolver, $buildId);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return null
     */
    private function prepare(OutputInterface $output, array $properties)
    {
        $this->logger->start($properties['build']);

        // Set emergency handler in case of super fatal
        if ($this->enableShutdownHandler) {
            register_shutdown_function([$this, 'blowTheHatch']);
        }

        $this->logger->event('success', sprintf('Found build: %s', $properties['build']->getId()));
        $this->logger->event('info', 'Resolved build properties', $properties);

        // add artifacts for cleanup
        $this->artifacts = $properties['artifacts'];
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function download(OutputInterface $output, array $properties)
    {
        $this->progress->enableDownloadProgress($output);

        $this->status($output, 'Downloading github repository');
        return call_user_func_array($this->downloader, [
            $properties['githubUser'],
            $properties['githubRepo'],
            $properties['githubReference'],
            $properties['buildFile']
        ]);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function unpack(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Unpacking github repository');
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
            $this->status($output, 'Skipping build command');
            return true;
        }

        $this->status($output, 'Running build command');
        return call_user_func_array($this->builder, [
            $properties['buildPath'],
            $properties['buildCommand'],
            $properties['environmentVariables']
        ]);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function pack(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Packing build into archive');
        return call_user_func(
            $this->packer,
            $properties['buildPath'],
            $properties['archiveFile']
        );
    }

    /**
     * Emergency failsafe
     */
    public function blowTheHatch()
    {
        $this->cleanup();

        // If we got to this point and the status is still "Building", something terrible has happened.
        $this->logger->failure();
    }
}
