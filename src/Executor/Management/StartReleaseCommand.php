<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Management;

use Hal\Agent\Command\IO;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\Runner\DeployCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Create a release and immediately deploy it.
 *
 * This command is a proxy from Management\CreateReleaseCommand to Runner\DeployCommand.
 */
class StartReleaseCommand implements ExecutorInterface
{
    /**
     * @var CreateReleaseCommand
     */
    private $creator;

    /**
     * @var DeployCommand
     */
    private $runner;

    /**
     * @param CreateReleaseCommand $creator
     * @param DeployCommand $runner
     */
    public function __construct(CreateReleaseCommand $creator, DeployCommand $runner)
    {
        $this->creator = $creator;
        $this->runner = $runner;
    }

    /**
     * @param Command $command
     *
     * @return void
     */
    public static function configure(Command $command)
    {
        CreateReleaseCommand::configure($command);

        $command
            ->setDescription('Create and deploy a release.');
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $exit = $this->creator->execute($io);

        if (!$release = $this->creator->release()) {
            return $exit;
        }

        // Need a better way to modify input/output on-the-fly when
        // we do not have access to Application or Command
        $io = new IO(
            new ArrayInput(
                [DeployCommand::PARAM_RELEASE => $release->id()],
                new InputDefinition([new InputArgument(DeployCommand::PARAM_RELEASE)])
            ),
            new ConsoleOutput
        );

        return $this->runner->execute($io);
    }
}
