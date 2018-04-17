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
use Hal\Agent\Symfony\ProcessRunner;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\Target;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Repository\JobType\ReleaseRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Cron worker that will pick up and run any "waiting" pushes.
 */
class DeployCommand implements ExecutorInterface
{
    use ExecutorTrait;
    use FormatterTrait;
    use JobStatsTrait;
    use WorkerTrait;

    const STEPS = [];

    const COMMAND_TITLE = 'Worker - Run pending deployments';
    const MSG_SUCCESS = 'All pending deployments were completed.';

    const INFO_NO_PENDING = 'No pending releases found.';

    const SUCCESS_JOB = 'Deployment Success: %s';
    const ERR_JOB = 'Deployment Failed: %s';
    const ERR_JOB_TIMEOUT = 'Deployment Timeout: %s';

    // 1 hour max
    const MAX_JOB_TIMEOUT = 3600;

    // Wait 5 seconds between checks
    const DEFAULT_SLEEP_TIME = 5;

    /**
     * @var ReleaseRepository
     */
    private $releaseRepo;

    /**
     * @var EntityManagerInterface
     */
    private $em;

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
     * @var array
     */
    private $deploymentCache;

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
        $this->releaseRepo = $em->getRepository(Release::class);
        $this->em = $em;
        $this->processRunner = $processRunner;
        $this->logger = $logger;
        $this->workingDir = $workingDir;

        $this->sleepTime = self::DEFAULT_SLEEP_TIME;
        $this->processes = [];
        $this->deploymentCache = [];
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
        if ($seconds > 0 && $seconds < 30) {
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
            ->setDescription('Find and deploy all pending releases.');
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $io->title(self::COMMAND_TITLE);

        if (!$releases = $this->releaseRepo->findBy(['status' => 'pending'])) {
            $io->note(self::INFO_NO_PENDING);
            return $this->success($io, self::MSG_SUCCESS);
        }

        $io->section('Starting pending deployments');
        $io->text(sprintf('Found %s releases:', count($releases)));

        foreach ($releases as $release) {
            $id = $release->id();
            $command = [
                'bin/hal',
                'runner:deploy',
                $id
            ];

            // Skip pushes without deployment target
            if (!$release->target()) {
                $io->listing([
                    sprintf("<fg=red>Skipping</> release: <info>%s</info>\n", $id) .
                    sprintf('   > Release <info>%s</info> has no target. Marking as failure.', $release->id())
                ]);

                $this->stopWeirdRelease($release);
                continue;
            }

            // Every time the worker runs we need to ensure all deployments spawned are unique.
            // This helps prevent concurrent deployments.
            if ($this->hasConcurrentDeployment($release->target())) {
                $io->listing([
                    sprintf("<fg=red>Skipping</> release: <info>%s</info>\n", $id) .
                    sprintf('   > A release to target <info>%s</info> is already in progress.', $release->target()->id())
                ]);

                continue;
            }

            $process = $this->processRunner->prepare($command, $this->workingDir, self::MAX_JOB_TIMEOUT);

            $io->listing([
                sprintf("Starting release: <info>%s</info>\n   > %s", $id, implode(' ', $command))
            ]);

            $this->processes[$id] = $process;
            $process->start();
        }

        $io->section('Waiting for running deployments to finish');

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
        $name = sprintf('Release %s', $id);
        $commandLine = $process->getCommandLine();

        $io->note(sprintf('Checking release status: <info>%s</info>', $id));

        $completed = $process->isTerminated();

        if ($completed) {
            $output = $this->outputJob($name, $process, false);

            $message = 'Release <info>%s</info> finished';
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

            $message = 'Release <info>%s</info> timed out';
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

    /**
     * @param Release $release
     *
     * @return void
     */
    private function stopWeirdRelease(Release $release)
    {
        $release->withStatus('failure');
        $this->em->merge($release);
        $this->em->flush();
    }

    /**
     * @param Target $target
     *
     * @return boolean
     */
    private function hasConcurrentDeployment(Target $target)
    {
        if (isset($this->deploymentCache[$target->id()])) {
            return true;
        }

        $this->deploymentCache[$target->id()] = true;
        return false;
    }
}
