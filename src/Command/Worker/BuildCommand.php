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
use QL\Hal\Core\Entity\Repository\BuildRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cron worker that will pick up and build any available builds.
 *
 * BUILT FOR COMMAND LINE ONLY
 */
class BuildCommand extends Command
{
    use CommandTrait;

    /**
     * A list of all possible exit codes of this command
     *
     * @var array
     */
    private static $codes = [
        0 => 'All waiting builds have been started.',
        1 => 'Could not fork a build worker.',
        2 => 'Build Command not found.'
    ];

    /**
     * @var string
     */
    private $buildCommand;

    /**
     * @var BuildRepository
     */
    private $buildRepo;

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
     * @param string $buildCommand
     * @param BuildRepository $buildRepo
     * @param EntityManager $entityManager
     * @param ForkHelper $forker
     */
    public function __construct(
        $name,
        $buildCommand,
        BuildRepository $buildRepo,
        EntityManager $entityManager,
        ForkHelper $forker
    ) {
        parent::__construct($name);
        $this->buildCommand = $buildCommand;

        $this->buildRepo = $buildRepo;
        $this->entityManager = $entityManager;
        $this->forker = $forker;
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
        if (!$builds = $this->buildRepo->findBy(['status' => 'Waiting'], null)) {
            return $this->success($output, 'No waiting builds found.');
        }

        $command = $this->getApplication()->find($this->buildCommand);
        if (!$command instanceof Command) {
            return $this->failure($output, 2);
        }

        $output->writeln(sprintf('Waiting builds: %s', count($builds)));
        $output->writeln('<comment>Starting build workers...</comment>');

        foreach ($builds as $build) {
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
                    'command' => $this->buildCommand,
                    'BUILD_ID' => $build->getId()
                ]);

                // Need to use buffered here because NullOutput doesn't have the correct verbosity methods
                return $command->run($input, new BufferedOutput);

            } else {
                $output->writeln(sprintf('Build ID %s started.', $build->getId()));
            }
        }

        return $this->success($output);
    }
}
