<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Push\Builder;
use QL\Hal\Agent\Push\CodeDelta;
use QL\Hal\Agent\Push\Pusher;
use QL\Hal\Agent\Push\Resolver;
use QL\Hal\Agent\Push\ServerCommand;
use QL\Hal\Agent\Push\Unpacker;
use QL\Hal\Core\Entity\Push;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;

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
     * @var array
     */
    private static $codes = [
        0 => 'Success!',
        1 => 'Push details could not be resolved.',
        2 => 'Build archive could not be unpacked.',
        4 => 'Pre push command failed.',
        8 => 'Push failed.',
        16 => 'Post push command failed.'
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
     * @var Unpacker
     */
    private $unpacker;

    /**
     * @var CodeDelta
     */
    private $delta;

    /**
     * @var Builder
     */
    private $builder;

    /**
     * @var Pusher
     */
    private $pusher;

    /**
     * @var ServerCommand
     */
    private $serverCommand;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

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
     * @param Unpacker $unpacker
     * @param CodeDelta $delta
     * @param Builder $builder
     * @param Pusher $pusher
     * @param ServerCommand $serverCommand
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(
        $name,
        EventLogger $logger,
        Resolver $resolver,
        Unpacker $unpacker,
        CodeDelta $delta,
        Builder $builder,
        Pusher $pusher,
        ServerCommand $serverCommand,
        ProcessBuilder $processBuilder
    ) {
        parent::__construct($name);

        $this->logger = $logger;

        $this->resolver = $resolver;
        $this->unpacker = $unpacker;
        $this->delta = $delta;
        $this->builder = $builder;
        $this->pusher = $pusher;
        $this->serverCommand = $serverCommand;

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
            ->setDescription('Deploy a previously built application to a server.')
            ->addArgument(
                'PUSH_ID',
                InputArgument::REQUIRED,
                'The ID of the push to deploy.'
            )
            ->addArgument(
                'METHOD',
                InputArgument::OPTIONAL,
                'The deployment method to use.',
                'rsync'
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
        // expected push statuses
        // Waiting, Pushing, Error, Success

        $pushId = $input->getArgument('PUSH_ID');
        $method = $input->getArgument('METHOD');

        // Add subscriptions
        $this->logger->addSubscription('push.success', 'notifier.email');
        $this->logger->addSubscription('push.failure', 'notifier.email');

        $this->logger->setStage('push.start');

        if (!$properties = $this->resolve($output, $pushId, $method)) {
            return $this->failure($output, 1);
        }

        // Set the push to in progress
        $this->prepare($output, $properties);

        if (!$this->unpack($output, $properties)) {
            return $this->failure($output, 2);
        }

        $this->delta($output, $properties);

        if (!$this->build($output, $properties)) {
            return $this->failure($output, 4);
        }

        $this->logger->setStage('pushing');

        if (!$this->prepush($output, $properties)) {
            return $this->failure($output, 8);
        }

        if (!$this->push($output, $properties)) {
            return $this->failure($output, 16);
        }

        if (!$this->postpush($output, $properties)) {
            return $this->failure($output, 32);
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
     * @param string $pushId
     * @param string $method
     * @return array|null
     */
    private function resolve(OutputInterface $output, $pushId, $method)
    {
        $this->status($output, 'Resolving push properties');
        return call_user_func($this->resolver, $pushId, $method);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
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
     * @return boolean
     */
    private function unpack(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Unpacking build archive');
        return call_user_func_array($this->unpacker, [
            $properties['archiveFile'],
            $properties['buildPath'],
            $properties['pushProperties']
        ]);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function delta(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Reading previous push data');
        return call_user_func_array($this->delta, [
            $properties['hostname'],
            $properties['remotePath'],
            $properties['pushProperties']
        ]);
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
    private function prepush(OutputInterface $output, array $properties)
    {
        if (!$properties['prePushCommand']) {
            $this->status($output, 'Skipping pre-push command');
            return true;
        }

        $this->status($output, 'Running pre-push command');
        return call_user_func_array($this->serverCommand, [
            $properties['hostname'],
            $properties['remotePath'],
            $properties['prePushCommand'],
            $properties['serverEnvironmentVariables']
        ]);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function push(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Pushing code to server');
        return call_user_func_array($this->pusher, [
            $properties['buildPath'],
            $properties['syncPath'],
            $properties['excludedFiles']
        ]);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function postpush(OutputInterface $output, array $properties)
    {
        if (!$properties['postPushCommand']) {
            $this->status($output, 'Skipping post-push command');
            return true;
        }

        $this->status($output, 'Running post-push command');
        return call_user_func_array($this->serverCommand, [
            $properties['hostname'],
            $properties['remotePath'],
            $properties['postPushCommand'],
            $properties['serverEnvironmentVariables']
        ]);
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
