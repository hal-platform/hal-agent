<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Worker;

use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use Hal\Agent\Executor\JobStatsTrait;
use Hal\Agent\Symfony\ProcessRunner;
use Hal\Core\Entity\JobType\Build;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

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
     * @var ObjectRepository
     */
    private $buildRepo;

    /**
     * @var ProcessRunner
     */
    private $processRunner;

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
     * @param ProcessRunner $processRunner
     * @param LoggerInterface $logger
     * @param string $workingDir
     */
    public function __construct(
        EntityManagerInterface $em,
        ProcessRunner $processRunner,
        LoggerInterface $logger,
        $workingDir
    ) {
        $this->buildRepo = $em->getRepository(Build::class);
        $this->processRunner = $processRunner;
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
    public function setSleepTime(int $seconds)
    {
        $seconds = $seconds;
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

            $process = $this->processRunner->prepare($command, $this->workingDir, self::MAX_JOB_TIMEOUT);

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
            $isDone = $this->waitOnProcess($io, $process, $id);
            if ($isDone) {
                unset($this->processes[$id]);
            } else {
                $allDone = false;
            }
        }

        if (!$allDone) {
            $io->note(sprintf('Waiting %d seconds...', $this->sleepTime));

            sleep($this->sleepTime);
            $this->wait($io);
        }
    }

    /**
     * @param IOInterface $io
     * @param Process $process
     * @param string $id
     *
     * @return bool
     */
    private function waitOnProcess(IOInterface $io, Process $process, $id)
    {
        $name = sprintf('Build %s', $id);
        $commandLine = $process->getCommandLine();

        $io->note(sprintf('Checking build status: <info>%s</info>', $id));

        $completed = $process->isTerminated();

        if ($completed) {
            $output = $this->outputJob($name, $process, false);

            $message = 'Build <info>%s</info> finished';
            $io->section(sprintf($message, $id));
            $io->text($output);

            if ($process->isSuccessful()) {
                $message = sprintf(self::SUCCESS_JOB, $id);
                $context = [
                    'output' => $process->getOutput(),
                    'command' => $commandLine
                ];

                $this->logger->info($message, $context);
            } else {
                $message = sprintf(self::ERR_JOB, $id);
                $context = [
                    'output' => $process->getOutput(),
                    'command' => $commandLine,
                    'errorOutput' => $process->getErrorOutput(),
                    'exitCode' => $process->getExitCode()
                ];

                $this->logger->warning($message, $context);
            }

            return true;
        }

        try {
            $process->checkTimeout();
        } catch (ProcessTimedOutException $ex) {
            $output = $this->outputJob($name, $process, true);

            $message = 'Build <info>%s</info> timed out';
            $io->section(sprintf($message, $id));
            $io->text($output);

            $message = sprintf(self::ERR_JOB_TIMEOUT, $id);
            $context = [
                'output' => $process->getOutput(),
                'command' => $commandLine,
                'maxTimeout' => $ex->getExceededTimeout(),
                'errorOutput' => $process->getErrorOutput()
            ];

            $this->logger->warning($message, $context);

            return true;
        }


        return false;
    }
}
