<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Doctrine\ORM\EntityManager;
use MCP\DataType\Time\Clock;
use QL\Hal\Agent\Helper\MemoryLogger;
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
     * @var MemoryLogger
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
     * @var Unpacker
     */
    private $unpacker;

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
     * @param MemoryLogger $logger
     * @param EntityManager $entityManager
     * @param Clock $clock
     * @param Resolver $resolver
     * @param Unpacker $unpacker
     * @param Pusher $pusher
     * @param ServerCommand $serverCommand
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(
        $name,
        MemoryLogger $logger,
        EntityManager $entityManager,
        Clock $clock,
        Resolver $resolver,
        Unpacker $unpacker,
        Pusher $pusher,
        ServerCommand $serverCommand,
        ProcessBuilder $processBuilder
    ) {
        parent::__construct($name);

        $this->logger = $logger;
        $this->entityManager = $entityManager;
        $this->clock = $clock;

        $this->resolver = $resolver;
        $this->unpacker = $unpacker;
        $this->pusher = $pusher;
        $this->serverCommand = $serverCommand;

        $this->processBuilder = $processBuilder;
        $this->artifacts = [];
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

        if (!$this->prepush($output, $properties)) {
            return $this->failure($output, 4);
        }

        if (!$this->push($output, $properties)) {
            return $this->failure($output, 8);
        }

        if (!$this->postpush($output, $properties)) {
            return $this->failure($output, 16);
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
        if ($this->push) {
            $status = ($exitCode === 0) ? 'Success' : 'Error';
            $this->push->setStatus($status);

            $this->push->setEnd($this->clock->read());
            $this->entityManager->merge($this->push);
            $this->entityManager->flush();
        }

        // Output log messages if verbosity is set
        // Output log context if debug verbosity
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
     * @param string $pushId
     * @param string $method
     * @return array|null
     */
    private function resolve(OutputInterface $output, $pushId, $method)
    {
        $output->writeln('<comment>Resolving...</comment>');
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

        // Update the push status asap so no other worker can pick it up
        $this->setEntityStatus('Pushing', true);

        $output->writeln(sprintf('<info>Push properties:</info> %s', json_encode($properties, JSON_PRETTY_PRINT)));

        // add artifacts for cleanup
        $this->artifacts = array_merge($this->artifacts, [$properties['buildPath']]);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function unpack(OutputInterface $output, array $properties)
    {
        $output->writeln('<comment>Unpacking...</comment>');
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
    private function prepush(OutputInterface $output, array $properties)
    {
        if (!$properties['prePushCommand']) {
            return true;
        }

        $this->logger->debug('Pre push command started', $this->timer());
        $output->writeln('<comment>Pre push command executing...</comment>');

        $success = call_user_func_array($this->serverCommand, [
            $properties['hostname'],
            $properties['remotePath'],
            $properties['prePushCommand'],
            $properties['environmentVariables']
        ]);

        if (!$success) {
            return false;
        }

        $this->logger->debug('Pre push command finished', $this->timer());
        return true;
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function push(OutputInterface $output, array $properties)
    {
        $this->logger->debug('Pushing started', $this->timer());
        $output->writeln('<comment>Pushing...</comment>');

        $success = call_user_func_array($this->pusher, [
            $properties['buildPath'],
            $properties['syncPath'],
            $properties['excludedFiles']
        ]);

        if (!$success) {
            return false;
        }

        $this->logger->debug('Pushing finished', $this->timer());
        return true;
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function postpush(OutputInterface $output, array $properties)
    {
        if (!$properties['postPushCommand']) {
            return true;
        }

        $this->logger->debug('Post push command started', $this->timer());
        $output->writeln('<comment>Post push command executing...</comment>');

        $success = call_user_func_array($this->serverCommand, [
            $properties['hostname'],
            $properties['remotePath'],
            $properties['postPushCommand'],
            $properties['environmentVariables']
        ]);

        if (!$success) {
            return false;
        }

        $this->logger->debug('Post push command finished', $this->timer());
        return true;
    }
}
