<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command\Worker;

use Doctrine\ORM\EntityManager;
use QL\Hal\Agent\Command\CommandTrait;
use QL\Hal\Agent\Helper\ForkHelper;
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
     * @param string $name
     * @param string $pushCommand
     * @param PushRepository $pushRepo
     * @param EntityManager $entityManager
     * @param ForkHelper $forker
     */
    public function __construct(
        $name,
        $pushCommand,
        PushRepository $pushRepo,
        EntityManager $entityManager,
        ForkHelper $forker
    ) {
        parent::__construct($name);
        $this->pushCommand = $pushCommand;

        $this->pushRepo = $pushRepo;
        $this->entityManager = $entityManager;
        $this->forker = $forker;
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
        if (!$pushes = $this->pushRepo->findBy(['status' => 'Waiting'], null)) {
            return $this->success($output, 'No waiting pushes found.');
        }

        $command = $this->getApplication()->find($this->pushCommand);
        if (!$command instanceof Command) {
            return $this->failure($output, 2);
        }

        $output->writeln(sprintf('Waiting pushes: %s', count($pushes)));
        $output->writeln('<comment>Starting push workers...</comment>');

        foreach ($pushes as $push) {
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
}
