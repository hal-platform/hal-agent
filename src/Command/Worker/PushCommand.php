<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command\Worker;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use QL\Hal\Agent\Command\CommandTrait;
use QL\Hal\Agent\Helper\ForkHelper;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Repository\PushRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cron worker that will pick up and push any available pushes.
 *
 * BUILT FOR COMMAND LINE ONLY
 */
class PushCommand extends Command
{
    use CommandTrait;

    /**
     * A list of all possible exit codes of this command
     *
     * @var array
     */
    private static $codes = [
        0 => 'All waiting pushes have been started.',
        1 => 'Could not fork a push worker.',
        2 => 'Push Command not found.'
    ];

    /**
     * @var string
     */
    private $pushCommand;

    /**
     * @var PushRepository
     */
    private $pushRepo;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var ForkHelper
     */
    private $forker;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    private $deploymentCache;

    /**
     * @param string $name
     * @param string $pushCommand
     * @param PushRepository $pushRepo
     * @param EntityManager $entityManager
     * @param ForkHelper $forker
     * @param LoggerInterface $logger
     */
    public function __construct(
        $name,
        $pushCommand,
        PushRepository $pushRepo,
        EntityManager $entityManager,
        ForkHelper $forker,
        LoggerInterface $logger
    ) {
        parent::__construct($name);
        $this->pushCommand = $pushCommand;

        $this->pushRepo = $pushRepo;
        $this->entityManager = $entityManager;
        $this->forker = $forker;
        $this->logger = $logger;

        $this->deploymentCache = [];
    }

    /**
     *  Configure the command
     */
    protected function configure()
    {
        $this->setDescription('Find and sync all waiting pushes.');
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
        if (!$pushes = $this->pushRepo->findBy(['status' => 'Waiting'])) {
            return $this->success($output, 'No waiting pushes found.');
        }

        $command = $this->getApplication()->find($this->pushCommand);
        if (!$command instanceof Command) {
            return $this->failure($output, 2);
        }

        $output->writeln(sprintf('Waiting pushes: %s', count($pushes)));
        $output->writeln('<comment>Starting push workers</comment>');

        foreach ($pushes as $push) {

            // Skip pushes without deployment target
            if (!$push->getDeployment()) {

                $push->setStatus('Error');
                $this->entityManager->merge($push);
                $this->entityManager->flush();

                $message = sprintf(
                    'Push ID %s error: It has no deployment target.',
                    $push->getId()
                );

                $output->writeln($message);
                $this->logger->info(sprintf('WORKER (Push) - %s', $message));

                continue;
            }

            // Every time the worker runs we need to ensure all deployments spawned are unique.
            // This helps prevent concurrent syncs.
            if ($this->hasConcurrentDeployment($push->getDeployment())) {
                $message = sprintf(
                    'Push ID %s skipped: A push to deployment %s is already running.',
                    $push->getId(),
                    $push->getDeployment()->getId()
                );
                $output->writeln($message);

                continue;
            }

            $pid = $this->forker->fork();
            if ($pid === -1) {
                return $this->failure($output, 1);

            } elseif ($pid === 0) {
                // child

                // reconnect db so the child has its own connection
                $connection = $this->entityManager->getConnection();
                $connection->close();
                $connection->connect();

                $input = new ArrayInput([
                    'command' => $this->pushCommand,
                    'PUSH_ID' => $push->getId()
                ]);

                return $command->run($input, new NullOutput);

            } else {
                $output->writeln(sprintf('Push ID %s started.', $push->getId()));
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
        if ($exitCode !== 0) {
            $message = (isset(static::$codes[$exitCode])) ? static::$codes[$exitCode] : 'An error occcured';
            $this->logger->critical(sprintf('WORKER (Push) - %s', $message));
        }

        return $exitCode;
    }

    /**
     * @param Deployment $deployment
     * @return boolean
     */
    private function hasConcurrentDeployment(Deployment $deployment)
    {
        if (isset($this->deploymentCache[$deployment->getId()])) {
            return true;
        }

        $this->deploymentCache[$deployment->getId()] = true;
        return false;
    }
}
