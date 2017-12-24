<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use Hal\Agent\Executor\JobStatsTrait;
use Hal\Core\Entity\Build;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Cron worker that will pick up and run any "waiting" builds.
 */
class BuildCommand implements ExecutorInterface
{
    use ExecutorTrait;
    use FormatterTrait;
    use JobStatsTrait;
    use WorkerTrait;

    const STEPS = [];

    const COMMAND_TITLE = 'Worker - Run pending builds';
    const MSG_SUCCESS = 'All pending builds were completed.';

    const INFO_NO_PENDING = 'No pending builds found.';

    const SUCCESS_JOB = 'Build Success: %s';
    const ERR_JOB = 'Build Failed: %s';
    const ERR_JOB_TIMEOUT = 'Build Timeout: %s';

    // 1 hour max
    const MAX_JOB_TIMEOUT = 3600;

    // Wait 5 seconds between checks
    const DEFAULT_SLEEP_TIME = 5;

    /**
     * @var EntityRepository
     */
    private $buildRepo;

    /**
     * @var ProcessBuilder
     */
    private $builder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Process[]
     */
    private $processes;

    /**
     * @var string
     */
    private $workingDir;

    /**
     * Sleep time in seconds
     *
     * @var int
     */
    private $sleepTime;

    /**
     * @param EntityManagerInterface $em
     * @param ProcessBuilder $builder
     * @param LoggerInterface $logger
     * @param string $workingDir
     */
    public function __construct(
        EntityManagerInterface $em,
        ProcessBuilder $builder,
        LoggerInterface $logger,
        $workingDir
    ) {
        $this->buildRepo = $em->getRepository(Build::class);
        $this->builder = $builder;
        $this->logger = $logger;
        $this->workingDir = $workingDir;

        $this->sleepTime = self::DEFAULT_SLEEP_TIME;
        $this->processes = [];

        $this->startTimer();
    }

    /**
     * Set a sleep time in seconds. Only values between 1-30 are allowed.
     *
     * @param int $seconds
     *
     * @return void
     */
    public function setSleepTime($seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds > 0 && $seconds <= 30) {
            $this->sleepTime = $seconds;
        }
    }

    /**
     * @param Command $command
     *
     * @return void
     */
    public static function configure(Command $command)
    {
        $command
            ->setDescription('Find and run all pending builds.');
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $io->title(self::COMMAND_TITLE);

        if (!$builds = $this->buildRepo->findBy(['status' => 'pending'])) {
            $io->note(self::INFO_NO_PENDING);

            return $this->success($io, self::MSG_SUCCESS);
        }

        $io->section('Starting pending builds');
        $io->text(sprintf('Found %s builds:', count($builds)));

        foreach ($builds as $build) {
            $id = $build->id();

            $command = [
                'bin/hal',
                'runner:build',
                $id
            ];

            $process = $this->builder
                ->setWorkingDirectory($this->workingDir)
                ->setArguments($command)
                ->setTimeout(self::MAX_JOB_TIMEOUT)
                ->getProcess();

            $io->listing([
                sprintf("Starting build: <info>%s</info>\n   > %s", $id, implode(' ', $command))
            ]);

            $this->processes[$id] = $process;
            $process->start();
        }

        $io->section('Waiting for running builds to finish');

        $this->wait($io);

        $this->outputJobStats($io);

        return $this->success($io, self::MSG_SUCCESS);
    }

    /**
     * @param IOInterface $io
     *
     * @return void
     */
    private function wait(IOInterface $io)
    {
        $allDone = true;
        foreach ($this->processes as $id => $process) {
            $name = sprintf('Build %s', $id);

            if ($process->isRunning()) {
                try {
                    $io->comment(sprintf('Checking build status: <info>%s</info>', $id));

                    $process->checkTimeout();
                    $allDone = false;

                } catch (ProcessTimedOutException $e) {
                    $output = $this->outputJob($name, $process, true);

                    $io->section(sprintf('Build <info>%s</info> timed out', $id));
                    $io->text($output);

                    $this->logger->warn(sprintf(self::ERR_JOB_TIMEOUT, $id), ['exceptionData' => $output]);

                    unset($this->processes[$id]);
                }

            } else {
                $output = $this->outputJob($name, $process, false);

                $io->section(sprintf('Build <info>%s</info> finished', $id));
                $io->text($output);

                if ($exit = $process->getExitCode()) {
                    $this->logger->info(sprintf(self::ERR_JOB, $id), ['exceptionData' => $output, 'exitCode' => $exit]);
                } else {
                    $this->logger->info(sprintf(self::SUCCESS_JOB, $id), ['exceptionData' => $output]);
                }

                unset($this->processes[$id]);
            }
        }

        if (!$allDone) {
            $io->comment(sprintf('Waiting %d seconds...', $this->sleepTime));

            sleep($this->sleepTime);
            $this->wait($io);
        }
    }
}
