<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command\Utility;

use QL\Hal\Core\Entity\Server;
use QL\Hal\Core\Repository\EnvironmentRepository;
use QL\Hal\Core\Repository\ServerRepository;
use QL\Hal\Agent\Command\CommandTrait;
use QL\Hal\Agent\Command\FormatterTrait;
use QL\Hal\Agent\Remoting\SSHSessionManager;
use QL\Hal\Agent\Utility\SortingHelperTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Verify HAL 9000's ability to connect to servers.
 *
 * BUILT FOR COMMAND LINE ONLY
 */
class VerifyServerConnectionsCommand extends Command
{
    use CommandTrait;
    use FormatterTrait;
    use SortingHelperTrait;

    /**
     * This is manually built so we can support incremental table rendering
     */
    const TABLE_HEADER = <<<STDOUT
+-------------+----------+-----------------------------------------------------+
| Environment | Status   | Hostname                                            |
+-------------+----------+-----------------------------------------------------+
STDOUT;
    const TABLE_ROW = <<<STDOUT
| %s | %s | %s |
STDOUT;
    const TABLE_SEPARATOR = <<<STDOUT
+-------------+----------+-----------------------------------------------------+
STDOUT;

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
        1 => 'Specified environment not found.',
    ];

    /**
     * @type ServerRepository
     */
    private $serverRepo;

    /**
     * @type EnvironmentRepository
     */
    private $environmentRepo;

    /**
     * @type SSHSessionManager
     */
    private $sshManager;

    /**
     * @param string $name
     * @param ServerRepository $serverRepo
     * @param EnvironmentRepository $environmentRepo
     * @param SSHSessionManager $sshManager
     */
    public function __construct(
        $name,
        ServerRepository $serverRepo,
        EnvironmentRepository $environmentRepo,
        SSHSessionManager $sshManager
    ) {
        parent::__construct($name);

        $this->serverRepo = $serverRepo;
        $this->environmentRepo = $environmentRepo;
        $this->sshManager = $sshManager;
    }


    /**
     * Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Verify HAL 9000 Agent can connect to all servers.')
            ->addArgument(
                'ENVIRONMENT_NAME',
                InputArgument::OPTIONAL,
                'Optionally limit verification to a single environment.'
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
        $environmentName = $input->getArgument('ENVIRONMENT_NAME') ?: '';
        $environment = null;

        if ($environmentName && !$environment = $this->environmentRepo->findOneBy(['key' => strtolower($environmentName)])) {
            return $this->failure($output, 1);
        }

        if ($environment) {
            $servers = $this->serverRepo->findBy(['environment' => $environment]);
        } else {
            $servers = $this->serverRepo->findAll();
        }

        if (!$servers) {
            return $this->status($output, 'No servers to check?');
        }

        $environments = $this->sortServersIntoEnvironments($servers);

        $output->write(self::TABLE_HEADER, true);
        foreach ($environments as $envName => $env) {
            foreach ($env as $server) {
                $row = $this->buildRow($server->getEnvironment()->getKey(), true, $server->getName());
                $output->writeln($row);
            }

            $output->writeln(self::TABLE_SEPARATOR);
        }

        return $this->finish($output, 0);
    }

    /**
     * @param string $env
     * @param string $status
     * @param string $hostname
     *
     * @return string
     */
    private function buildRow($env, $status, $hostname)
    {
        $display = $status ? '<info>PASS</info>' : '<error>FAIL</error>';
        $display = sprintf('[ %s ]', $display);
        return sprintf(
            self::TABLE_ROW,
            str_pad($env, 11),
            str_pad($display, 8),
            str_pad($hostname, 51)
        );
    }

    /**
     * @param Server[] $servers
     *
     * @return array
     */
    private function sortServersIntoEnvironments(array $servers)
    {
        $environments = [];

        // Add servers to groupings
        foreach ($servers as $server) {
            $envName = $server->getEnvironment()->getKey();

            if (!array_key_exists($envName, $environments)) {
                $environments[$envName] = [];
            }

            $environments[$envName][] = $server;
        }

        // sort envs
        usort($environments, $this->envSorter());

        // Sort servers within env
        $sorter = $this->serverSorter();
        foreach ($environments as &$env) {
            usort($env, $sorter);
        }

        return $environments;
    }

    /**
     * @return Closure
     */
    private function envSorter()
    {
        return function($a, $b) {
            $envA = $a[0]->getEnvironment()->getOrder();
            $envB = $b[0]->getEnvironment()->getOrder();

            return ($envA < $envB) ? -1 : 1;
        };
    }
}
