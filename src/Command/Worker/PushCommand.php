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
use QL\Hal\Agent\Command\CommandTrait;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Repository\PushRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Cron worker that will pick up and push any available pushes.
 *
 * BUILT FOR COMMAND LINE ONLY
 */
class PushCommand extends Command
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
     * @var PushRepository
     */
    private $pushRepo;

    /**
     * @var EntityManager
     */
    private $entityManager;

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

        $this->pushRepo = $em->getRepository(Push::CLASS);
        $this->em = $em;
        $this->builder = $builder;
        $this->logger = $logger;
        $this->workingDir = $workingDir;

        $this->sleepTime = self::DEFAULT_SLEEP_TIME;
        $this->processes = [];
        $this->deploymentCache = [];
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
        $this->setDescription('Find and sync all waiting pushes.');
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

        if (!$pushes = $this->pushRepo->findBy(['status' => 'Waiting'])) {
            $this->write('No waiting pushes found.');
            return $this->finish($output, 0);
        }

        $this->status(sprintf('Waiting pushes: %s', count($pushes)), 'Worker');

        foreach ($pushes as $push) {
            $id = $push->id();
            $command = [
                'bin/hal',
                'push:push',
                $id
            ];

            // Skip pushes without deployment target
            if (!$push->deployment()) {
                $this->stopWeirdPush($push);
                continue;
            }

            // Every time the worker runs we need to ensure all deployments spawned are unique.
            // This helps prevent concurrent syncs.
            if ($this->hasConcurrentDeployment($push->deployment())) {
                $deploymentId = $push->deployment()->id();
                $msg = sprintf('Skipping push: <info>%s</info> - A push to deployment <info>%s</info> is already running', $push->id(), $deploymentId);
                $this->status($msg, 'Worker');
                continue;
            }

            $process = $this->builder
                ->setWorkingDirectory($this->workingDir)
                ->setArguments($command)
                ->setTimeout(self::MAX_JOB_TIMEOUT)
                ->getProcess();

            $this->status(sprintf('Starting push: <info>%s</info>', $id), 'Worker');
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
                    $this->status(sprintf('Checking push status: <info>%s</info>', $id), 'Worker');

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

    /**
     * @param Push $push
     *
     * @return void
     */
    private function stopWeirdPush(Push $push)
    {
        $this->status(sprintf('Push %s has no deployment. Marking as error.', $push->id()), 'Worker');

        $push->withStatus('Error');
        $this->em->merge($push);
        $this->em->flush();
    }

    /**
     * @param Deployment $deployment
     *
     * @return boolean
     */
    private function hasConcurrentDeployment(Deployment $deployment)
    {
        if (isset($this->deploymentCache[$deployment->id()])) {
            return true;
        }

        $this->deploymentCache[$deployment->id()] = true;
        return false;
    }
}
