<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Push\ConfigurationReader;
use QL\Hal\Agent\Push\DelegatingDeployer;
use QL\Hal\Agent\Push\Mover;
use QL\Hal\Agent\Push\Pusher;
use QL\Hal\Agent\Push\Resolver;
use QL\Hal\Agent\Push\Unpacker;
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
class PushCommand extends Command
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
        1 => 'Push details could not be resolved.',
        2 => 'Build archive could not be copied to local storage.',
        3 => 'Build archive could not be unpacked.',
        4 => '.hal9000.yml configuration was invalid and could not be read.',
        5 => 'Deployment failed.',

        100 => 'Required properties for rsync are missing.',
        101 => 'Build transform command failed.',
        102 => 'Pre push command failed.',
        103 => 'Rsync push failed.',
        104 => 'Post push command failed.',

        200 => 'Required properties for EB are missing.',
        201 => 'Elastic Beanstalk environment is not ready.',
        202 => 'Build transform command failed.',
        203 => 'Build could not be packed for S3.',
        204 => 'Upload to S3 failed.',
        205 => 'Deploying application to EB failed.'
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
     * @type Mover
     */
    private $mover;

    /**
     * @type Unpacker
     */
    private $unpacker;

    /**
     * @type ConfigurationReader
     */
    private $reader;

    /**
     * @type DelegatingDeployer
     */
    private $deployer;

    /**
     * @type Filesystem
     */
    private $filesystem;

    /**
     * @type Push|null
     */
    private $push;

    /**
     * @type boolean
     */
    private $enableShutdownHandler;

    /**
     * @param string $name
     * @param EventLogger $logger
     * @param Resolver $resolver
     * @param Mover $mover
     * @param Unpacker $unpacker
     * @param ConfigurationReader $reader
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
        DelegatingDeployer $deployer,
        Filesystem $filesystem
    ) {
        parent::__construct($name);

        $this->logger = $logger;

        $this->resolver = $resolver;
        $this->mover = $mover;
        $this->reader = $reader;
        $this->unpacker = $unpacker;
        $this->deployer = $deployer;

        $this->filesystem = $filesystem;
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
        // expected push statuses
        // Waiting, Pushing, Error, Success

        $pushId = $input->getArgument('PUSH_ID');

        // Add subscriptions
        $this->logger->addSubscription('push.success', 'notifier.email');
        $this->logger->addSubscription('push.failure', 'notifier.email');

        $this->logger->setStage('push.start');

        if (!$properties = $this->resolve($output, $pushId)) {
            return $this->failure($output, 1);
        }

        // Set the push to in progress
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
        if (!$this->read($output, $properties)) {
            return $this->failure($output, 4);
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

        return $exitCode;
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     *
     * @return null
     */
    private function status(OutputInterface $output, $message)
    {
        $message = sprintf('<comment>%s</comment>', $message);
        $output->writeln($message);
    }

    /**
     * @param OutputInterface $output
     * @param string $pushId
     *
     * @return array|null
     */
    private function resolve(OutputInterface $output, $pushId)
    {
        $this->status($output, 'Resolving push properties');

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
        $this->logger->start($properties['push']);
        $this->status($output, sprintf('Found push: %s', $properties['push']->getId()));

        // Set emergency handler in case of super fatal
        if ($this->enableShutdownHandler) {
            register_shutdown_function([$this, 'blowTheHatch']);
        }

        $context = $properties;
        unset($context['pushProperties']);
        unset($context['artifacts']);

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
        $this->status($output, 'Moving archive to local storage');

        $mover = $this->mover;
        return $mover($properties['location']['archive'], $properties['location']['tempArchive']);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function unpack(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Unpacking build archive');

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
     *
     * @return bool
     */
    private function deploy(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Deploying application');

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
