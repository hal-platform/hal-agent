<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Doctrine\ORM\EntityManager;
use MCP\DataType\Time\Clock;
use QL\Hal\Agent\Logger\CommandLoggingTrait;
use QL\Hal\Agent\Push\Builder;
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
    use CommandLoggingTrait;
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
     * @var Unpacker
     */
    private $unpacker;

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
     * @param string $name
     * @param EntityManager $entityManager
     * @param Clock $clock
     * @param Resolver $resolver
     * @param Unpacker $unpacker
     * @param Builder $builder
     * @param Pusher $pusher
     * @param ServerCommand $serverCommand
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(
        $name,
        EntityManager $entityManager,
        Clock $clock,
        Resolver $resolver,
        Unpacker $unpacker,
        Builder $builder,
        Pusher $pusher,
        ServerCommand $serverCommand,
        ProcessBuilder $processBuilder
    ) {
        parent::__construct($name);

        $this->entityManager = $entityManager;
        $this->clock = $clock;

        $this->resolver = $resolver;
        $this->unpacker = $unpacker;
        $this->builder = $builder;
        $this->pusher = $pusher;
        $this->serverCommand = $serverCommand;

        $this->processBuilder = $processBuilder;
        $this->artifacts = [];
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

        if (!$properties = $this->resolve($output, $pushId, $method)) {
            return $this->failure($output, 1);
        }

        $this->prepare($output, $properties);

        if (!$this->unpack($output, $properties)) {
            return $this->failure($output, 2);
        }

        if (!$this->build($output, $properties)) {
            return $this->failure($output, 4);
        }

        if (!$this->prepush($output, $properties)) {
            return $this->failure($output, 8);
        }

        if (!$this->push($output, $properties)) {
            return $this->failure($output, 16);
        }

        if (!$this->postpush($output, $properties)) {
            return $this->failure($output, 32);
        }

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
        if ($this->push) {
            $status = ($exitCode === 0) ? 'Success' : 'Error';
            $this->push->setStatus($status);

            $this->push->setEnd($this->clock->read());
            $this->entityManager->merge($this->push);
            $this->entityManager->flush();

            // Only send logs if the push was found
            $type = ($exitCode === 0) ? 'success' : 'failure';

            $this->logAndFlush($type, [
                'push' => $this->push,
                'pushId' => $this->push->getId(),
                'pushExitCode' => $exitCode
            ]);
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
        if (!$this->push) {
            return;
        }

        $this->push->setStatus($status);
        if ($start) {
            $this->push->setStart($this->clock->read());
        }

        $this->entityManager->merge($this->push);
        $this->entityManager->flush();
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return null
     */
    private function status(OutputInterface $output, $message)
    {
        $this->log('notice', $message);

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
        $this->push = $properties['push'];

        // Set emergency handler in case of super fatal
        $this->inCaseOfEmergency([$this, 'blowTheHatch']);

        // Update the push status asap so no other worker can pick it up
        $this->setEntityStatus('Pushing', true);

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
            $properties['environmentVariables']
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
            $properties['environmentVariables']
        ]);
    }

    /**
     * Emergency failsafe
     */
    public function blowTheHatch()
    {
        $this->cleanup();

        // If we got to this point and the status is still "Pushing", something terrible has happened.
        if ($this->push && $this->push->getStatus() === 'Pushing') {
            $this->push->setEnd($this->clock->read());
            $this->setEntityStatus('Error');

            $this->logAndFlush('failure', [
                'push' => $this->push,
                'pushId' => $this->push->getId(),
                'pushExitCode' => $exitCode
            ]);
        }
    }
}
