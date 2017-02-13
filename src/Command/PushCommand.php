<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Command;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Build\ConfigurationReader;
use Hal\Agent\Build\DelegatingBuilder;
use Hal\Agent\Push\DelegatingDeployer;
use Hal\Agent\Push\Mover;
use Hal\Agent\Push\Pusher;
use Hal\Agent\Push\Resolver;
use Hal\Agent\Push\Unpacker;
use Hal\Agent\Symfony\OutputAwareInterface;
use Hal\Agent\Symfony\OutputAwareTrait;
use QL\Hal\Core\Entity\Push;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Push a previously built application to a server.
 */
class PushCommand extends Command implements OutputAwareInterface
{
    use CommandTrait;
    use FormatterTrait;
    use OutputAwareTrait;

    const SECTION_START = 'Starting Deployment';
    const SECTION = 'Deploying';
    const SECTION_BUILDING = 'Building';

    /**
     * A list of all possible exit codes of this command
     *
     * @var array
     */
    private static $codes = [
        0 => 'Success!',
        1 => 'Push details could not be resolved.',
        2 => 'Build archive could not be copied to local storage.',
        3 => 'Build archive could not be unpacked.',
        4 => '.hal9000.yml configuration was invalid and could not be read.',
        5 => 'Build transform failed.',
        6 => 'Deployment failed.',

        100 => 'Required properties for rsync are missing.',
        101 => 'Could not verify target directory.',
        102 => 'Pre push command failed.',
        103 => 'Rsync push failed.',
        104 => 'Post push command failed.',

        200 => 'Required properties for EB are missing.',
        201 => 'Failed to authenticate with AWS.',
        202 => 'Elastic Beanstalk environment is not ready.',
        203 => 'Build could not be packed for S3.',
        204 => 'Upload to S3 failed.',
        205 => 'Deploying application to EB failed.',

        300 => 'Required properties for script are missing.',
        301 => 'No deployment scripts are defined.',
        302 => 'Deployment command failed.',

        400 => 'Required properties for S3 are missing.',
        401 => 'Failed to authenticate with AWS.',
        402 => 'Build could not be packed for S3.',
        403 => 'Upload to S3 failed.',

        500 => 'Required properties for CodeDeploy are missing.',
        501 => 'Failed to authenticate with AWS.',
        502 => 'CodeDeploy group is not ready.',
        503 => 'Build could not be packed for S3.',
        504 => 'Upload to S3 failed.',
        505 => 'Deploying application to CodeDeploy failed.'
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
     * @var Push|null
     */
    private $push;

    /**
     * @var boolean
     */
    private $enableShutdownHandler;

    /**
     * @param string $name
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
        $name,
        EventLogger $logger,
        Resolver $resolver,
        Mover $mover,
        Unpacker $unpacker,
        ConfigurationReader $reader,
        DelegatingBuilder $builder,
        DelegatingDeployer $deployer,
        Filesystem $filesystem
    ) {
        parent::__construct($name);

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
     * Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Deploy a previously built application to a server.')
            ->addArgument(
                'PUSH_ID',
                InputArgument::REQUIRED,
                'The ID of the push to deploy.'
            );

        $help = ['<fg=cyan>Exit codes:</fg=cyan>'];
        foreach (static::$codes as $code => $message) {
            $help[] = $this->formatSection($code, $message);
        }
        $this->setHelp(implode("\n", $help));
    }

    /**
     * Run the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setOutput($output);

        // expected push statuses
        // Waiting, Pushing, Error, Success

        $pushId = $input->getArgument('PUSH_ID');

        $this->logger->setStage('push.start');

        if (!$properties = $this->resolve($output, $pushId)) {
            return $this->failure($output, 1);
        }

        $this->prepare($output, $properties);

        // move archive to local temp location
        if (!$this->move($output, $properties)) {
            return $this->failure($output, 2);
        }

        // unpack
        if (!$this->unpack($output, $properties)) {
            return $this->failure($output, 3);
        }

        // read hal9000.yml
        // NOTE: this action takes properties by reference and MODIFIES configuration IN PLACE
        if (!$this->read($output, $properties)) {
            return $this->failure($output, 4);
        }

        // build (transform)
        if (!$this->build($output, $properties)) {
            return $this->failure($output, 5);
        }

        // deploy
        if (!$this->deploy($output, $properties)) {
            return $this->failure($output, $this->deployer->getExitCode());
        }

        $this->logger->setStage('end');

        // finish
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
    }

    /**
     * @param OutputInterface $output
     * @param int $exitCode
     *
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
        $this->outputMemoryUsage($output);
        $this->outputTimer($output);

        return $exitCode;
    }

    /**
     * @param OutputInterface $output
     * @param string $pushId
     *
     * @return array|null
     */
    private function resolve(OutputInterface $output, $pushId)
    {
        $this->status('Resolving push properties', self::SECTION_START);

        $resolver = $this->resolver;
        return $resolver($pushId);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return null
     */
    private function prepare(OutputInterface $output, array $properties)
    {
        // Set the push to in progress
        $this->logger->start($properties['push']);

        $foundApp = sprintf('Application: <info>%s</info>', $properties['push']->application()->name());
        $foundEnv = sprintf('Environment: <info>%s</info>', $properties['push']->build()->environment()->name());
        $foundPush = sprintf('Push: <info>%s</info>', $properties['push']->id());
        $foundBuild = sprintf('Build: <info>%s</info>', $properties['push']->build()->id());

        $this->status($foundApp, self::SECTION_START);
        $this->status($foundEnv, self::SECTION_START);
        $this->status($foundPush, self::SECTION_START);
        $this->status($foundBuild, self::SECTION_START);

        // Set emergency handler in case of super fatal
        if ($this->enableShutdownHandler) {
            register_shutdown_function([$this, 'blowTheHatch']);
        }

        // Mangle context
        $context =  [
            'defaultConfiguration' => $properties['configuration'],
            'method' => $properties['method'],
            'location' => $properties['location']
        ];

        if (isset($properties['encryptedSources'])) {
            $context['encrypted'] = $properties['encryptedSources'];
        }

        $this->logger->event('success', 'Resolved push properties', $context);

        // add artifacts for cleanup
        $this->artifacts = $properties['artifacts'];
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function move(OutputInterface $output, array $properties)
    {
        $this->status('Moving archive to local storage', self::SECTION_START);

        $mover = $this->mover;
        return $mover($properties['location']['archive'], $properties['location']['tempArchive']);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return bool
     */
    private function unpack(OutputInterface $output, array $properties)
    {
        $this->status('Unpacking build archive', self::SECTION_START);

        $unpacker = $this->unpacker;
        return $unpacker(
            $properties['location']['tempArchive'],
            $properties['location']['path'],
            $properties['pushProperties']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return bool
     */
    private function read(OutputInterface $output, array &$properties)
    {
        $this->status('Reading .hal9000.yml', self::SECTION_START);

        $reader = $this->reader;
        return $reader(
            $properties['location']['path'],
            $properties['configuration']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return bool
     */
    private function build(OutputInterface $output, array $properties)
    {
        if (!$properties['configuration']['build_transform']) {
            $this->status('Skipping build transform command', self::SECTION_BUILDING);
            return true;
        }

        $this->status('Running build transform command', self::SECTION_BUILDING);

        $builder = $this->builder;

        return $builder(
            $output,
            $properties['configuration']['system'],
            $properties['configuration']['build_transform'],
            $properties
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return bool
     */
    private function deploy(OutputInterface $output, array $properties)
    {
        $this->status('Deploying application', self::SECTION);

        $deployer = $this->deployer;
        return $deployer($output, $properties['method'], $properties);
    }

    /**
     * Emergency failsafe
     */
    public function blowTheHatch()
    {
        $this->cleanup();

        // If we got to this point and the status is still "Pushing", something terrible has happened.
        $this->logger->failure();
    }
}
