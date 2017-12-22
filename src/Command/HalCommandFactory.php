<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Command;

use Hal\Agent\Executor\ExecutorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Factory for building Symfony Commands that are lazy loaded.
 */
class HalCommandFactory
{
    /**
     * @var ContainerInterface
     */
    private $di;

    /**
     * @param ContainerInterface $di
     */
    public function __construct(ContainerInterface $di)
    {
        $this->di = $di;
    }

    /**
     * Build a symfony command that is lazy loaded.
     *
     * @param string $name
     * @param string $service
     * @param callable $configurator
     *
     * @return Command
     */
    public function build($name, $service, callable $configurator = null)
    {
        $command = new Command($name);

        if ($configurator) {
            $configurator($command);
        }

        $execution = $this->buildExecutor($service);

        $command->setCode($execution);

        return $command;
    }

    /**
     * @param string $service
     *
     * @return callable
     */
    private function buildExecutor($service)
    {
        return function (InputInterface $input, OutputInterface $output) use ($service) {
            $io = new IO($input, $output);
            $executor = $this->di->get($service);

            if (!$executor instanceof ExecutorInterface) {
                $io->error(sprintf('The service "%s" is not a valid executor.', $service));
                return 1;
            }

            return $executor->execute($io);
        };
    }
}
