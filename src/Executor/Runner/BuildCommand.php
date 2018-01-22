<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Runner;

use Hal\Agent\Build\BuildException;
use Hal\Agent\Build\ConfigurationReader;
use Hal\Agent\Build\DelegatingBuilder;
use Hal\Agent\Build\Downloader;
use Hal\Agent\Build\Mover;
use Hal\Agent\Build\Packer;
use Hal\Agent\Build\Resolver;
use Hal\Agent\Build\Unpacker;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use Hal\Agent\Executor\JobStatsTrait;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\SSHSessionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Build an application for a particular environment.
 *
 * The amount of dependencies of this command is too damn high.
 */
class BuildCommand implements ExecutorInterface
{
    use ExecutorTrait;
    use FormatterTrait;
    use JobStatsTrait;

    const COMMAND_TITLE = 'Runner - Run build';
    const MSG_SUCCESS = 'Build was run successfully.';

    const PARAM_BUILD = 'BUILD_ID';
    const HELP_BUILD = 'The ID of the Build to run.';

    const STEPS = [
        1 => 'Resolving configuration',
        2 => 'Downloading source code',
        3 => 'Unpacking source code',
        4 => 'Reading .hal.yml configuration',
        5 => 'Running build process',
        6 => 'Packing build artifact',
        7 => 'Exporting build artifact',
    ];

    const ERR_NOT_RUNNABLE = 'Build cannot be run.';
    const ERR_DOWNLOAD = 'Source code cannot be downloaded.';
    const ERR_UNPACK = 'Source code cannot be unpacked.';
    const ERR_CONFIG = '.hal.yml configuration is invalid and cannot be read.';
    const ERR_BUILD = 'Build process failed.';
    const ERR_PACK = 'Build artifact cannot be packed.';
    const ERR_EXPORT = 'Build artifact cannot be exported to artifact repository.';

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
     * @var ConfigurationReader
     */
    private $reader;

    /**
     * @var DelegatingBuilder
     */
    private $builder;

    /**
     * @var Packer
     */
    private $packer;

    /**
     * @var Mover
     */
    private $mover;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var SSHSessionManager
     */
    private $sshManager;

    /**
     * @var string[]
     */
    private $artifacts;

    /**
     * @var boolean
     */
    private $enableShutdownHandler;

    /**
     * @var callable|null
     */
    private $cleanup;

    /**
     * @param EventLogger $logger
     * @param Resolver $resolver
     * @param Downloader $downloader
     * @param Unpacker $unpacker
     * @param ConfigurationReader $reader
     * @param DelegatingBuilder $builder
     * @param Packer $packer
     * @param Mover $mover
     * @param Filesystem $filesystem
     * @param SSHSessionManager $sshManager
     */
    public function __construct(
        EventLogger $logger,
        Resolver $resolver,
        Downloader $downloader,
        Unpacker $unpacker,
        ConfigurationReader $reader,
        DelegatingBuilder $builder,
        Packer $packer,
        Mover $mover,
        Filesystem $filesystem,
        SSHSessionManager $sshManager
    ) {
        $this->logger = $logger;

        $this->resolver = $resolver;
        $this->downloader = $downloader;
        $this->unpacker = $unpacker;
        $this->reader = $reader;
        $this->builder = $builder;
        $this->packer = $packer;
        $this->mover = $mover;

        $this->filesystem = $filesystem;
        $this->sshManager = $sshManager;

        $this->artifacts = [];

        $this->enableShutdownHandler = true;
        $this->startTimer();
    }

    /**
     * In case of error or critical failure, ensure that we clean up the build artifacts.
     *
     * Note that this is only called for exceptions and non-fatal errors. Fatal errors WILL NOT trigger this.
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->enableShutdownHandler) {
            $this->emergencyCleanup();
        }
    }

    /**
     * Used during unit testing or other scenarios when we shouldn't attach to global state.
     *
     * @return void
     */
    public function disableShutdownHandler()
    {
        $this->enableShutdownHandler = false;
    }

    /**
     * @param Command $command
     *
     * @return void
     */
    public static function configure(Command $command)
    {
        $command
            ->setDescription('Run an application build.')

            ->addArgument(self::PARAM_BUILD, InputArgument::REQUIRED, self::HELP_BUILD);
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $buildID = $io->getArgument(self::PARAM_BUILD);

        $io->title(self::COMMAND_TITLE);

        $this->logger->setStage('build.start');

        if (!$properties = $this->resolve($io, $buildID)) {
            return $this->buildFailure($io, self::ERR_NOT_RUNNABLE);
        }

        if (!$this->download($io, $properties)) {
            return $this->buildFailure($io, self::ERR_DOWNLOAD);
        }

        if (!$this->unpack($io, $properties)) {
            return $this->buildFailure($io, self::ERR_UNPACK);
        }

        if (!$properties = $this->read($io, $properties)) {
            return $this->buildFailure($io, self::ERR_CONFIG);
        }

        if (!$this->build($io, $properties)) {
            $io->note($this->builder->getFailureMessage());
            return $this->buildFailure($io, self::ERR_BUILD);
        }

        $this->logger->setStage('end');

        if (!$this->pack($io, $properties)) {
            return $this->buildFailure($io, self::ERR_PACK);
        }

        if (!$this->export($io, $properties)) {
            return $this->buildFailure($io, self::ERR_EXPORT);
        }

        $this->outputJobStats($io);

        return $this->buildSuccess($io, self::MSG_SUCCESS);
    }

    /**
     * @param IOInterface $io
     * @param string $buildID
     *
     * @return array|null
     */
    private function resolve(IOInterface $io, $buildID)
    {
        $io->section($this->step(1));

        try {
            $properties = ($this->resolver)($buildID);
        } catch (BuildException $ex) {
            $io->caution($ex->getMessage());
            $properties = null;
        }

        if ($properties) {
            $this->prepare($io, $properties);
        }

        return $properties;
    }

    /**
     * @param IOInterface $io
     * @param array $properties
     *
     * @return void
     */
    private function prepare(IOInterface $io, array $properties)
    {
        $build = $properties['build'];

        // Set the build to in progress
        $this->logger->start($build);

        if ($environment = $build->environment()) {
            $environmentName = $environment->name();
            $environmentID = $environment->id();
        } else {
            $environmentName = 'Any';
            $environmentID = 'N/A';
        }

        $io->listing([
            sprintf('Build: <info>%s</info>', $build->id()),
            sprintf('Application: <info>%s</info> (ID: %s)', $build->application()->name(), $build->application()->id()),
            sprintf('Environment: <info>%s</info> (ID: %s)', $environmentName, $environmentID)
        ]);

        // The build has officially started running.
        // We must set an emergency handler in case of super fatal to ensure it is always
        // safely finished and clean-up is run.
        $this->prepareCleanup($io);
        if ($this->enableShutdownHandler) {
            register_shutdown_function([$this, 'emergencyCleanup']);
        }

        $this->logConfigurationEvent($properties);

        // add artifacts for cleanup
        $this->artifacts = $properties['artifacts'];
    }

    /**
     * @param array $properties
     *
     * @return void
     */
    private function logConfigurationEvent(array $properties)
    {
        // Explicitly define which config is passed through to the event, since the entire propreties
        // may contain sensitive information.
        $context = [
            'defaultConfiguration' => $properties['configuration'],
            'github' => $properties['github'],
            'location' => $properties['location']
        ];

        // Add "resolved encrypted config" if used.
        if (isset($properties['encryptedSources'])) {
            $context['encrypted'] = $properties['encryptedSources'];
        }

        // Add an event
        $this->logger->event('success', 'Resolved build properties', $context);
    }

    /**
     * @param IOInterface $io
     * @param array $properties
     *
     * @return bool
     */
    private function download(IOInterface $io, array $properties)
    {
        $io->section($this->step(2));

        $io->listing([
            sprintf('Source repository: <info>%s/%s</info>', $properties['github']['user'], $properties['github']['repo']),
            sprintf('Source reference: <info>%s</info>', $properties['github']['reference']),
            sprintf('Target: <info>%s</info>', $properties['location']['download'])
        ]);

        return ($this->downloader)(
            $properties['github']['user'],
            $properties['github']['repo'],
            $properties['github']['reference'],
            $properties['location']['download']
        );
    }

    /**
     * @param IOInterface $io
     * @param array $properties
     *
     * @return bool
     */
    private function unpack(IOInterface $io, array $properties)
    {
        $io->section($this->step(3));

        $io->listing([
            sprintf('Source file: <info>%s</info>', $properties['location']['download']),
            sprintf('Build directory: <info>%s</info>', $properties['location']['path']),
        ]);

        return ($this->unpacker)(
            $properties['location']['download'],
            $properties['location']['path']
        );
    }

    /**
     * @param IOInterface $io
     * @param array $properties
     *
     * @return bool|array
     */
    private function read(IOInterface $io, array &$properties)
    {
        $io->section($this->step(4));

        $config = ($this->reader)(
            $properties['location']['path'],
            $properties['configuration']
        );

        if (!$config) {
            return null;
        }

        $rows = [];
        foreach ($config as $p => $v) {
            $rows[] = [$p, json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)];
        }

        $io->listing(['Application configuration:']);
        $io->table(['Configuration', 'Value'], $rows);

        $properties['configuration'] = $config;
        return $properties;
    }

    /**
     * @param IOInterface $io
     * @param array $properties
     *
     * @return bool
     */
    private function build(IOInterface $io, array $properties)
    {
        $io->section($this->step(5));

        $platform = $properties['configuration']['image'] ? $properties['configuration']['platform'] : 'default';
        $image = $properties['configuration']['image'] ? $properties['configuration']['image'] : 'default';

        if (!$properties['configuration']['build']) {
            $io->text('No build commands found. Skipping build process.');
            return true;
        }

        $io->listing([
            sprintf('Platform: <info>%s</info>', $platform),
            sprintf('Platform Image: <info>%s</info>', $image)
        ]);

        $io->text('Commands:');
        $io->listing($this->colorize($properties['configuration']['build']));

        return ($this->builder)(
            $io,
            $properties['configuration']['platform'],
            $properties['configuration']['image'],
            $properties['configuration']['build'],
            $properties
        );
    }

    /**
     * @param IOInterface $io
     * @param array $properties
     *
     * @return bool
     */
    private function pack(IOInterface $io, array $properties)
    {
        $io->section($this->step(6));

        $io->listing([
            sprintf('Build directory: <info>%s</info>', $properties['location']['path']),
            sprintf('Artifact path: <info>%s</info>', $properties['configuration']['dist']),
            sprintf('Artifact file: <info>%s</info>', $properties['location']['path'])
        ]);

        return ($this->packer)(
            $properties['location']['path'],
            $properties['configuration']['dist'],
            $properties['location']['tempArchive']
        );
    }

    /**
     * @param IOInterface $io
     * @param array $properties
     *
     * @return bool
     */
    private function export(IOInterface $io, array $properties)
    {
        $io->section($this->step(7));

        $io->listing([
            sprintf('Artifact file: <info>%s</info>', $properties['location']['tempArchive']),
            sprintf('Repository storage: <info>%s</info>', $properties['location']['archive'])
        ]);

        return ($this->mover)(
            $properties['location']['tempArchive'],
            $properties['location']['archive']
        );
    }

    /**
     * @param IOInterface $io
     *
     * @return void
     */
    private function prepareCleanup(IOInterface $io)
    {
        $this->cleanup = function () use ($io) {

            if ($this->artifacts) {
                $io->section('Build clean-up');

                $io->text('Build artifacts to remove:');
                $io->listing($this->colorize($this->artifacts));

                $io->text('Disconnecting all open SSH connections.');
            }

            foreach ($this->artifacts as $artifact) {
                try {
                    $this->filesystem->remove($artifact);

                } catch (IOException $e) {
                }
            }

            // Clear artifacts
            $this->artifacts = [];

            // Disconnect any active ssh sessions
            $this->sshManager->disconnectAll();
        };
    }

    /**
     * Cleanup (if prepared) should only run once. So successive calls to this method will have no effect.
     *
     * This is useful because we can attach cleanup events to many processes
     * (error handler, destruct, success, etc) and not worry about duplicate calls.
     *
     * @return void
     */
    private function cleanup()
    {
        if ($this->cleanup) {
            ($this->cleanup)();
        }

        $this->cleanup = null;
    }

    /**
     * @param IOInterface $io
     * @param string $message
     *
     * @return int
     */
    private function buildFailure(IOInterface $io, $message)
    {
        $this->logger->failure();
        $this->cleanup();

        return $this->failure($io, $message);
    }

    /**
     * @param IOInterface $io
     * @param string $message
     *
     * @return int
     */
    private function buildSuccess(IOInterface $io, $message)
    {
        $this->logger->success();
        $this->cleanup();

        return $this->success($io, $message);
    }

    /**
     * Emergency failsafe
     */
    public function emergencyCleanup()
    {
        $this->cleanup();

        // If we got to this point and the status is still "Building", something terrible has happened.
        // The EventLogger will only fail builds that aren't finished yet.
        $this->logger->failure();
    }
}
