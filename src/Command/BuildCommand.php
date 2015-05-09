<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use QL\Hal\Agent\Build\ConfigurationReader;
use QL\Hal\Agent\Build\DelegatingBuilder;
use QL\Hal\Agent\Build\Downloader;
use QL\Hal\Agent\Build\Mover;
use QL\Hal\Agent\Build\Packer;
use QL\Hal\Agent\Build\Resolver;
use QL\Hal\Agent\Build\Unpacker;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Remoting\SSHSessionManager;
use QL\Hal\Agent\Symfony\GuzzleDownloadProgress;
use QL\Hal\Core\Entity\Build;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

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
     * @type array
     */
    private static $codes = [
        0 => 'Success!',
        1 => 'Build details could not be resolved.',
        2 => 'Repository archive could not be downloaded.',
        3 => 'Repository archive could not be unpacked.',
        4 => '.hal9000.yml configuration was invalid and could not be read.',
        5 => 'Build archive could not be created.',
        6 => 'Build could not be moved to archive.',

        100 => 'Required properties for unix are missing.',
        101 => 'Exporting files to build server failed.',
        102 => 'Encryption failure.',
        103 => 'Build command failed.',
        104 => 'Importing files from build server failed.',

        200 => 'Required properties for windows are missing.',
        201 => 'Exporting files to build server failed.',
        202 => 'Encryption failure.',
        203 => 'Build command failed.',
        204 => 'Importing files from build server failed.',
    ];

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type Resolver
     */
    private $resolver;

    /**
     * @type Downloader
     */
    private $downloader;

    /**
     * @type Unpacker
     */
    private $unpacker;

    /**
     * @type ConfigurationReader
     */
    private $reader;

    /**
     * @type DelegatingBuilder
     */
    private $builder;

    /**
     * @type Packer
     */
    private $packer;

    /**
     * @type Mover
     */
    private $mover;

    /**
     * @type GuzzleDownloadProgress
     */
    private $progress;

    /**
     * @type Filesystem
     */
    private $filesystem;

    /**
     * @type SSHSessionManager
     */
    private $sshManager;

    /**
     * @type string[]
     */
    private $artifacts;

    /**
     * @type boolean
     */
    private $enableShutdownHandler;

    /**
     * @param string $name
     * @param EventLogger $logger
     * @param Resolver $resolver
     * @param Downloader $downloader
     * @param Unpacker $unpacker
     * @param ConfigurationReader $reader
     * @param DelegatingBuilder $builder
     * @param Packer $packer
     * @param Mover $mover
     * @param GuzzleDownloadProgress $progress
     * @param Filesystem $filesystem
     * @param SSHSessionManager $sshManager
     */
    public function __construct(
        $name,
        EventLogger $logger,
        Resolver $resolver,
        Downloader $downloader,
        Unpacker $unpacker,
        ConfigurationReader $reader,
        DelegatingBuilder $builder,
        Packer $packer,
        Mover $mover,
        GuzzleDownloadProgress $progress,
        Filesystem $filesystem,
        SSHSessionManager $sshManager
    ) {
        parent::__construct($name);

        $this->logger = $logger;

        $this->resolver = $resolver;
        $this->downloader = $downloader;
        $this->unpacker = $unpacker;
        $this->reader = $reader;
        $this->builder = $builder;
        $this->packer = $packer;
        $this->mover = $mover;

        $this->progress = $progress;
        $this->filesystem = $filesystem;
        $this->sshManager = $sshManager;

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

        // download
        if (!$this->download($output, $properties)) {
            return $this->failure($output, 2);
        }

        // unpack
        if (!$this->unpack($output, $properties)) {
            return $this->failure($output, 3);
        }

        // read .hal9000.yml
        if (!$this->read($output, $properties)) {
            return $this->failure($output, 4);
        }

        // build
        if (!$this->build($output, $properties)) {
            return $this->failure($output, $this->builder->getExitCode());
        }

        $this->logger->setStage('end');

        // pack
        if (!$this->pack($output, $properties)) {
            return $this->failure($output, 5);
        }

        // move to archive
        if (!$this->move($output, $properties)) {
            return $this->failure($output, 6);
        }

        $this->success($output);
    }

    /**
     * @return null
     */
    private function cleanup()
    {
        foreach ($this->artifacts as $artifact) {
            try {
                $this->filesystem->remove($artifact);
            } catch (IOException $e) {}
        }

        // Clear artifacts
        $this->artifacts = [];

        // Disconnect any active ssh sessions
        $this->sshManager->disconnectAll();
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

        $resolver = $this->resolver;
        return $resolver($buildId);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return null
     */
    private function prepare(OutputInterface $output, array $properties)
    {
        $this->logger->start($properties['build']);
        $this->status($output, sprintf('Found build: %s', $properties['build']->getId()));

        // Set emergency handler in case of super fatal
        if ($this->enableShutdownHandler) {
            register_shutdown_function([$this, 'blowTheHatch']);
        }

        // Mangle context
        $context =  [
            'defaultConfiguration' => $properties['configuration'],
            'github' => $properties['github'],
            'location' => $properties['location']
        ];

        if (isset($properties['encryptedSources'])) {
            $context['encrypted'] = $properties['encryptedSources'];
        }

        $this->logger->event('success', 'Resolved build properties', $context);

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

        $downloader = $this->downloader;
        return $downloader(
            $properties['github']['user'],
            $properties['github']['repo'],
            $properties['github']['reference'],
            $properties['location']['download']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function unpack(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Unpacking github repository');

        $unpacker = $this->unpacker;
        return $unpacker(
            $properties['location']['download'],
            $properties['location']['path']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function read(OutputInterface $output, array &$properties)
    {
        $this->status($output, 'Reading .hal9000.yml');

        $reader = $this->reader;
        return $reader(
            $properties['location']['path'],
            $properties['configuration']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function build(OutputInterface $output, array $properties)
    {
        if (!$properties['configuration']['build']) {
            $this->status($output, 'Skipping building');
            return true;
        }

        $this->status($output, 'Building');

        $builder = $this->builder;
        return $builder(
            $output,
            $properties['configuration']['system'],
            $properties['configuration']['build'],
            $properties
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function pack(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Packing build into archive');

        $packer = $this->packer;
        return $packer(
            $properties['location']['path'],
            $properties['configuration']['dist'],
            $properties['location']['tempArchive']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function move(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Moving build to archive');

        $mover = $this->mover;
        return $mover($properties['location']['tempArchive'], $properties['location']['archive']);
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
