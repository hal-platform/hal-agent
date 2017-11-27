<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Runner;

use Hal\Agent\Build\ConfigurationReader;
use Hal\Agent\Build\DelegatingBuilder;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use Hal\Agent\Executor\JobStatsTrait;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Push\DelegatingDeployer;
use Hal\Agent\Push\Mover;
use Hal\Agent\Push\PushException;
use Hal\Agent\Push\Resolver;
use Hal\Agent\Push\Unpacker;
use Hal\Core\Entity\Release;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Deploy a release of an application to a particular environment.
 *
 * The amount of dependencies of this command is too damn high.
 */
class DeployCommand implements ExecutorInterface
{
    use ExecutorTrait;
    use FormatterTrait;
    use JobStatsTrait;

    const COMMAND_TITLE = 'Runner - Deploy release';
    const MSG_SUCCESS = 'Release was deployed successfully.';

    const PARAM_RELEASE = 'RELEASE_ID';
    const HELP_RELEASE = 'The ID of the Release to deploy.';

    const STEPS = [
        1 => 'Resolving configuration',
        2 => 'Importing build artifact',
        3 => 'Unpacking build artifact',
        4 => 'Reading .hal.yml configuration',
        5 => 'Running build transform process',
        6 => 'Running build deployment process'
    ];

    const ERR_NOT_RUNNABLE = 'Release cannot be run.';
    const ERR_IMPORT = 'Build artifact cannot be imported from artifact repository.';
    const ERR_UNPACK = 'Build artifact cannot be unpacked.';
    const ERR_CONFIG = '.hal.yml configuration is invalid and cannot be read.';
    const ERR_TRANSFORM = 'Build transform process failed.';
    const ERR_DEPLOY = 'Deployment process failed.';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Resolver
     */
    private $resolver;

    /**
     * @var Mover
     */
    private $mover;

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
     * @var DelegatingDeployer
     */
    private $deployer;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Release|null
     */
    private $release;

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
     * @param Mover $mover
     * @param Unpacker $unpacker
     * @param ConfigurationReader $reader
     * @param DelegatingBuilder $builder
     * @param DelegatingDeployer $deployer
     * @param Filesystem $filesystem
     */
    public function __construct(
        EventLogger $logger,
        Resolver $resolver,
        Mover $mover,
        Unpacker $unpacker,
        ConfigurationReader $reader,
        DelegatingBuilder $builder,
        DelegatingDeployer $deployer,
        Filesystem $filesystem
    ) {
        $this->logger = $logger;

        $this->resolver = $resolver;
        $this->mover = $mover;
        $this->reader = $reader;
        $this->unpacker = $unpacker;

        $this->builder = $builder;
        $this->deployer = $deployer;

        $this->filesystem = $filesystem;
        $this->artifacts = [];

        $this->enableShutdownHandler = true;
        $this->startTimer();
    }

    /**
     * In case of error or critical failure, ensure that we clean up the build artifacts.
     *
     * Note that this is only called for exceptions and non-fatal errors.
     * Fatal errors WILL NOT trigger this.
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
            ->setDescription('Deploy a previously built application.')

            ->addArgument(self::PARAM_RELEASE, InputArgument::REQUIRED, self::HELP_RELEASE);
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $releaseID = $io->getArgument(self::PARAM_RELEASE);

        $io->title(self::COMMAND_TITLE);

        $this->logger->setStage('release.start');

        if (!$properties = $this->resolve($io, $releaseID)) {
            return $this->deploymentFailure($io, self::ERR_NOT_RUNNABLE);
        }

        if (!$this->import($io, $properties)) {
            return $this->deploymentFailure($io, self::ERR_IMPORT);
        }

        if (!$this->unpack($io, $properties)) {
            return $this->deploymentFailure($io, self::ERR_UNPACK);
        }

        if (!$properties = $this->read($io, $properties)) {
            return $this->deploymentFailure($io, self::ERR_CONFIG);
        }

        if (!$this->build($io, $properties)) {
            return $this->deploymentFailure($io, self::ERR_TRANSFORM);
        }

        if (!$this->deploy($io, $properties)) {
            $io->note($this->deployer->getFailureMessage());
            return $this->deploymentFailure($io, self::ERR_DEPLOY);
        }

        $this->logger->setStage('end');

        $this->outputJobStats($io);

        return $this->deploymentSuccess($io, self::MSG_SUCCESS);
    }

    /**
     * @param IOInterface $io
     * @param string $releaseID
     *
     * @return array|null
     */
    private function resolve(IOInterface $io, $releaseID)
    {
        $io->section($this->step(1));

        try {
            $properties = ($this->resolver)($releaseID);
        } catch (PushException $ex) {
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
        $release = $properties['release'];
        $build = $release->build();

        // Set the push to in progress
        $this->logger->start($release);

        $application = $release->application();
        $environment = $release->target()->group()->environment();

        $io->listing([
            sprintf('Release: <info>%s</info>', $release->id()),
            sprintf('Build: <info>%s</info>', $build->id()),
            sprintf('Application: <info>%s</info> (ID: %s)', $application->name(), $application->id()),
            sprintf('Environment: <info>%s</info> (ID: %s)', $environment->name(), $environment->id())
        ]);

        // The deployment has officially started running.
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
            'method' => $properties['method'],
            'location' => $properties['location']
        ];

        // Add "resolved encrypted config" if used.
        if (isset($properties['encryptedSources'])) {
            $context['encrypted'] = $properties['encryptedSources'];
        }

        // Add an event
        $this->logger->event('success', 'Resolved push properties', $context);
    }

    /**
     * @param IOInterface $io
     * @param array $properties
     *
     * @return boolean
     */
    private function import(IOInterface $io, array $properties)
    {
        $io->section($this->step(2));

        $io->listing([
            sprintf('Artifact file: <info>%s</info>', $properties['location']['archive']),
            sprintf('Target: <info>%s</info>', $properties['location']['tempArchive'])
        ]);

        return ($this->mover)(
            $properties['location']['archive'],
            $properties['location']['tempArchive']
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
            sprintf('Source file: <info>%s</info>', $properties['location']['tempArchive']),
            sprintf('Release directory: <info>%s</info>', $properties['location']['path'])
        ]);

        return ($this->unpacker)(
            $properties['location']['tempArchive'],
            $properties['location']['path'],
            $properties['pushProperties']
        );
    }

    /**
     * @param IOInterface $io
     * @param array $properties
     *
     * @return array|null
     */
    private function read(IOInterface $io, array $properties)
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

        if (!$properties['configuration']['build_transform']) {
            $io->text('No build transform commands found. Skipping transform process.');
            return true;
        }

        $io->listing([
            sprintf('System: <info>%s</info>', $properties['configuration']['system'])
        ]);

        $io->text('Commands:');
        $io->listing($this->colorize($properties['configuration']['build_transform']));

        return ($this->builder)(
            $io,
            $properties['configuration']['system'],
            $properties['configuration']['build_transform'],
            $properties
        );
    }

    /**
     * @param IOInterface $io
     * @param array $properties
     *
     * @return bool
     */
    private function deploy(IOInterface $io, array $properties)
    {
        $io->section($this->step(6));

        $io->listing([
            sprintf('Method: <info>%s</info>', $properties['method'])
        ]);

        return ($this->deployer)(
            $io,
            $properties['method'],
            $properties
        );
    }

    /**
     * @param IOInterface $io
     *
     * @return void
     */
    private function prepareCleanup(IOInterface $io)
    {
        $this->cleanup = function() use ($io) {

            if ($this->artifacts) {
                $io->section('Deployment clean-up');

                $io->text('Deployment artifacts to remove:');
                $io->listing($this->colorize($this->artifacts));
            }

            foreach ($this->artifacts as $artifact) {
                try {
                    $this->filesystem->remove($artifact);
                } catch (IOException $e) {}
            }

            // Clear artifacts
            $this->artifacts = [];
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
    private function deploymentFailure(IOInterface $io, $message)
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
    private function deploymentSuccess(IOInterface $io, $message)
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

        // If we got to this point and the status is still "Pushing", something terrible has happened.
        // The EventLogger will only fail deploys that aren't finished yet.
        $this->logger->failure();
    }
}
