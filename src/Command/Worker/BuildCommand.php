<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Command\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Psr\Log\LoggerInterface;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Agent\Command\CommandTrait;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;

/**
 * Cron worker that will pick up and build any available builds.
 *
 * BUILT FOR COMMAND LINE ONLY
 */
class BuildCommand extends Command implements OutputAwareInterface
{
    use CommandTrait;
    use OutputAwareTrait;
    use WorkerTrait;

    const SUCCESS_JOB = 'Build Failed: %s';
    const ERR_JOB = 'Build Success: %s';
    const ERR_JOB_TIMEOUT = 'Build Timeout: %s';

    // 1 hour max
    const MAX_JOB_TIMEOUT = 3600;

    // Wait 5 seconds between checks
    const DEFAULT_SLEEP_TIME = 5;

    /**
     * @type EntityRepository
     */
    private $buildRepo;

    /**
     * @type ProcessBuilder
     */
    private $builder;

    /**
     * @type LoggerInterface
     */
    private $logger;

    /**
     * @type Process[]
     */
    private $processes;

    /**
     * @type string
     */
    private $workingDir;

    /**
     * Sleep time in seconds
     *
     * @type int
     */
    private $sleepTime;

    /**
     * @param string $name
     * @param EntityManagerInterface $em
     * @param ProcessBuilder $builder
     * @param LoggerInterface $logger
     * @param string $workingDir
     */
    public function __construct(
        $name,
        EntityManagerInterface $em,
        ProcessBuilder $builder,
        LoggerInterface $logger,
        $workingDir
    ) {
        parent::__construct($name);

        $this->buildRepo = $em->getRepository(Build::CLASS);
        $this->builder = $builder;
        $this->logger = $logger;
        $this->workingDir = $workingDir;

        $this->sleepTime = self::DEFAULT_SLEEP_TIME;
        $this->processes = [];

        $this->startTimer();
    }

    /**
     * @param int $seconds
     *
     * @return void
     */
    public function setSleepTime($seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds > 0 && $seconds < 30) {
            $this->sleepTime = $seconds;
        }
    }

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this->setDescription('Find and build all waiting builds.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setOutput($output);

        if (!$builds = $this->buildRepo->findBy(['status' => 'Waiting'], null)) {
            $this->write('No waiting builds found.');
            return $this->finish($output, 0);
        }

        $this->status(sprintf('Waiting builds: %s', count($builds)), 'Worker');

        foreach ($builds as $build) {
            $id = $build->id();
            $command = [
                'bin/hal',
                'build:build',
                $id
            ];

            $process = $this->builder
                ->setWorkingDirectory($this->workingDir)
                ->setArguments($command)
                ->setTimeout(self::MAX_JOB_TIMEOUT)
                ->getProcess();

            $this->status(sprintf('Starting build: <info>%s</info>', $id), 'Worker');
            $this->processes[$id] = $process;

            $process->start();
        }

        $this->wait();

        return $this->finish($output, 0);
    }

    /**
     * @return void
     */
    private function wait()
    {
        $allDone = true;
        foreach ($this->processes as $id => $process) {
            if ($process->isRunning()) {
                try {
                    $this->status(sprintf('Checking build status: <info>%s</info>', $id), 'Worker');

                    $process->checkTimeout();
                    $allDone = false;

                } catch (ProcessTimedOutException $e) {
                    $output = $this->outputJob($id, $process, true);
                    $this->write($output);

                    $this->logger->warn(sprintf(self::ERR_JOB_TIMEOUT, $id), ['exceptionData' => $output]);

                    unset($this->processes[$id]);
                }

            } else {
                $output = $this->outputJob($id, $process, false);
                $this->write($output);

                if ($exit = $process->getExitCode()) {
                    $this->logger->info(sprintf(self::ERR_JOB, $id), ['exceptionData' => $output, 'exitCode' => $exit]);
                } else {
                    $this->logger->info(sprintf(self::SUCCESS_JOB, $id), ['exceptionData' => $output]);
                }

                unset($this->processes[$id]);
            }
        }

        if (!$allDone) {
            $this->status(sprintf('Waiting %d seconds...', $this->sleepTime), 'Worker');
            sleep($this->sleepTime);
            $this->wait();
        }
    }
}
