<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command\Docker;

use QL\Hal\Agent\Command\CommandTrait;
use QL\Hal\Agent\Command\FormatterTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Nuke all docker images
 *
 * Remove untagged docker images:
 * ```
 * docker rmi $(docker images | grep "^<none>" | awk '{print $3}')
 * ```
 *
 * BUILT FOR COMMAND LINE ONLY
 */
class NukeImagesCommand extends Command
{
    use CommandTrait;
    use FormatterTrait;

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
     * @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $argument = $input->getArgument('SAMPLE_ARG') ?: 'default';

        $this->status($output, 'stdout status');

        if (false) {
            return $this->failure($output, 1);
        }

        return $this->success($output, 'Success!');
    }
}
