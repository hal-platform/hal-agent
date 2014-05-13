<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use QL\Hal\Core\Entity\Repository\BuildRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cron worker that will pick up and build any available builds.
 */
class WorkerbuildCommand extends Command
{
    use CommandTrait;
    use FormatterTrait;

    /**
     * A list of all possible exit codes of this command
     *
     * @var array
     */
    private static $codes = [
        0 => 'All waiting builds have been started.',
        1 => 'Could not fork a build worker.'
    ];

    /**
     * @var string
     */
    private $buildCommand;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var BuildRepository
     */
    private $buildRepo;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @param string $name
     * @param string $buildCommand
     * @param LoggerInterface $logger
     * @param BuildRepository $buildRepo
     * @param EntityManager $entityManager
     */
    public function __construct(
        $name,
        $buildCommand,
        LoggerInterface $logger,
        BuildRepository $buildRepo,
        EntityManager $entityManager
    ) {
        parent::__construct($name);
        $this->buildCommand = $buildCommand;

        $this->logger = $logger;
        $this->buildRepo = $buildRepo;
        $this->entityManager = $entityManager;
    }

    /**
     *  Configure the command
     */
    protected function configure()
    {
        $this->setDescription('Find and build all waiting builds.');
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
        // find a build
        if (!$builds = $this->buildRepo->findBy(['status' => 'Waiting'], null)) {
            $output->writeln('No waiting builds found.');
            return $this->success($output, '');
        }

        // Get build command
        $command = $this->getApplication()->find($this->buildCommand);

        $this->logger->info(sprintf('Found %s waiting builds', count($builds)));
        $output->writeln(sprintf('Waiting builds: %s', count($builds)));

        $output->writeln('<comment>Starting build workers...</comment>');

        foreach ($builds as $build) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                return $this->failure($output, 1);

            } elseif ($pid === 0) {
                // child

                // reconnect db so the child has its own connection
                $connection = $this->entityManager->getConnection();
                $connection->close();
                $connection->connect();

                $input = new ArrayInput([
                    'command' => $this->buildCommand,
                    'BUILD_ID' => $build->getId()
                ]);

                // Need to use buffered here because NullOutput doesn't have the correct verbosity methods
                return $command->run($input, new BufferedOutput);

            } else {
                $this->logger->info('Build worker started', ['buildId' => $build->getId()]);
                $output->writeln(sprintf('Build ID %s started.', $build->getId()));
            }
        }

        return $this->success($output);
    }

    /**
     * @param OutputInterface $output
     * @param int $exitCode
     * @return null
     */
    private function finish(OutputInterface $output, $exitCode)
    {
        // Output log messages if verbosity is set
        // Output log context if debug verbosity
        if ($output->isVerbose() && $loggerOutput = $this->logger->output($output->isVeryVerbose())) {
            $output->writeln($loggerOutput);
        }

        return $exitCode;
    }
}
