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
use Hal\Agent\Executor\Runner\BuildCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Create a build and immediately start it.
 *
 * This command is a proxy from Management\CreateBuildCommand to Runner\BuildCommand.
 */
class StartBuildCommand implements ExecutorInterface
{
    /**
     * @var CreateBuildCommand
     */
    private $creator;

    /**
     * @var BuildCommand
     */
    private $runner;

    /**
     * @param CreateBuildCommand $creator
     * @param BuildCommand $runner
     */
    public function __construct(CreateBuildCommand $creator, BuildCommand $runner)
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
        CreateBuildCommand::configure($command);

        $command
            ->setDescription('Create and run a build for an environment.');
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $exit = $this->creator->execute($io);

        if (!$build = $this->creator->build()) {
            return $exit;
        }

        // Need a better way to modify input/output on-the-fly when
        // we do not have access to Application or Command
        $io = new IO(
            new ArrayInput(
                [BuildCommand::PARAM_BUILD => $build->id()],
                new InputDefinition([new InputArgument(BuildCommand::PARAM_BUILD)])
            ),
            new ConsoleOutput
        );

        return $this->runner->execute($io);
    }
}
