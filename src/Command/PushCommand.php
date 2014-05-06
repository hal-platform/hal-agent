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
use QL\Hal\Agent\Push\Pusher;
use QL\Hal\Agent\Push\Resolver;
use QL\Hal\Agent\Push\Unpacker;
use QL\Hal\Core\Entity\Push;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;

/**
 *  Push a previously built application to a server.
 */
class PushCommand extends Command
{
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
     * @var Unpacker
     */
    private $unpacker;

    /**
     * @var Pusher
     */
    private $pusher;

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
     * @param LoggerInterface $logger
     * @param EntityManager $entityManager
     * @param Clock $clock
     * @param Resolver $resolver
     * @param Unpacker $unpacker
     * @param Pusher $pusher
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(
        $name,
        LoggerInterface $logger,
        EntityManager $entityManager,
        Clock $clock,
        Resolver $resolver,
        Unpacker $unpacker,
        Pusher $pusher,
        ProcessBuilder $processBuilder
    ) {
        parent::__construct($name);

        $this->logger = $logger;
        $this->entityManager = $entityManager;
        $this->clock = $clock;

        $this->resolver = $resolver;
        $this->unpacker = $unpacker;
        $this->pusher = $pusher;

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

        // resolve
        $output->writeln('<comment>Resolving...</comment>');
        if (!$properties = call_user_func($this->resolver, $pushId, $method)) {
            $this->error($output, 'Push details could not be resolved.');
            return 1;
        }

        $this->push = $properties['push'];

        // Update the push status asap so no other worker can pick it up
        $this->setEntityStatus('Pushing', true);

        $output->writeln(sprintf('<info>Push properties:</info> %s', json_encode($properties, JSON_PRETTY_PRINT)));

        // add artifacts for cleanup
        $this->artifacts = array_merge($this->artifacts, [$properties['buildPath']]);

        // unpack
        $unpackProperties = [
            $properties['archiveFile'],
            $properties['buildPath'],
            $properties['pushProperties']
        ];

        $output->writeln('<comment>Unpacking...</comment>');
        if (!call_user_func_array($this->unpacker, $unpackProperties)) {
            $this->error($output, 'Build archive could not be unpacked.');
            return 2;
        }

        // push
        $pushProperties = [
            $properties['buildPath'],
            $properties['syncPath'],
            $properties['excludedFiles']
        ];

        $this->logger->debug('Pushing started', $this->timer());
        $output->writeln('<comment>Pushing...</comment>');
        if (!call_user_func_array($this->pusher, $pushProperties)) {
            $this->error($output, 'Push failed.');
            return 4;
        }
        $this->logger->debug('Pushing finished', $this->timer());

        // finish
        $this->success($output);
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return null
     */
    private function error(OutputInterface $output, $message)
    {
        if ($this->push) {
            $this->push->setStatus('Error');
        }

        $this->finish($output);
        $output->writeln(sprintf('<error>%s</error>', $message));
    }

    /**
     * Duplicated from BuildCommand
     *
     * @param OutputInterface $output
     * @param string $message
     * @return null
     */
    private function finish(OutputInterface $output)
    {
        if ($this->push) {
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
    }

    /**
     * Duplicated from BuildCommand
     *
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
     * Duplicated from BuildCommand
     *
     * @param OutputInterface $output
     * @return null
     */
    private function success(OutputInterface $output)
    {
        if ($this->push) {
            $this->push->setStatus('Success');
        }

        $this->finish($output);
        $output->writeln(sprintf('<question>%s</question>', 'Success!'));
    }

    /**
     * @var array $context
     * @return array
     */
    private function timer(array $context = [])
    {
        return array_merge(
            $context,
            ['time' => $this->clock->read()->format('H:i:s', 'America/Detroit')]
        );
    }
}
