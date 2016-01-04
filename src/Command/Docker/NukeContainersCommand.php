<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Command\Docker;

use QL\Hal\Agent\Command\CommandTrait;
use QL\Hal\Agent\Command\FormatterTrait;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Nuke all containers, even running ones!
 *
 * ```
 * docker ps -aq | xargs docker rm -f
 * ```
 *
 * BUILT FOR COMMAND LINE ONLY
 */
class NukeContainersCommand extends Command implements OutputAwareInterface
{
    use CommandTrait;
    use FormatterTrait;
    use OutputAwareTrait;

    const STATIC_HELP = <<<'HELP'
<fg=cyan>Exit codes:</fg=cyan>
HELP;

    /**
     * A list of all possible exit codes of this command
     *
     * @var array
     */
    private static $codes = [
        0 => 'Success',
        1 => 'Error',
    ];

    /**
     * @param string $name
     */
    public function __construct(
        $name
    ) {
        parent::__construct($name);
    }


    /**
     * Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Description of command.')
            ->addArgument(
                'SAMPLE_ARG',
                InputArgument::OPTIONAL,
                'Description of arg.'
            );

        $help = [self::STATIC_HELP];
        foreach (static::$codes as $code => $message) {
            $help[] = $this->formatSection($code, $message);
        }

        $this->setHelp(implode("\n", $help));
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setOutput($output);

        $argument = $input->getArgument('SAMPLE_ARG') ?: 'default';

        if (false) {
            return $this->failure($output, 1);
        }

        return $this->success($output, 'Success!');
    }
}
