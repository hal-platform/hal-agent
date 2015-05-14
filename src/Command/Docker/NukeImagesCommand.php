<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
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
 * Nuke all docker images
 *
 * Remove untagged docker images:
 * ```
 * docker rmi $(docker images | grep "^<none>" | awk '{print $3}')
 * ```
 * docker images --filter "dangling=true" | xargs docker rmi
 *
 * > sudo du -sh /var/lib/docker
 * 1.3G    /var/lib/docker
 *
 * > df -ah /var
 * Filesystem              Size  Used Avail Use% Mounted on
 * /dev/mapper/vg00-lvvar  8.4G  1.7G  6.8G  20% /var
 *
 * BUILT FOR COMMAND LINE ONLY
 */
class NukeImagesCommand extends Command implements OutputAwareInterface
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
