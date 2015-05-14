<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command\Worker;

use Psr\Log\LoggerInterface;
use QL\Hal\Agent\Command\CommandTrait;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
use QL\Hal\Core\Repository\BuildRepository;
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

    // 1 hour max
    const MAX_JOB_TIMEOUT = 3600;

    // Wait 5 seconds between checks
    const DEFAULT_SLEEP_TIME = 5;

    /**
     * @type BuildRepository
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
     * @param BuildRepository $buildRepo
     * @param ProcessBuilder $builder
     * @param LoggerInterface $logger
     * @param string $workingDir
     */
    public function __construct(
        $name,
        BuildRepository $buildRepo,
        ProcessBuilder $builder,
        LoggerInterface $logger,
        $workingDir
    ) {
        parent::__construct($name);

        $this->buildRepo = $buildRepo;
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
            $id = $build->getId();
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
                    $this->write($this->outputJob($id, $process, true));
                    unset($this->processes[$id]);
                }

            } else {
                $this->write($this->outputJob($id, $process, false));
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
