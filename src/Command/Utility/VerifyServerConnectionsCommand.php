<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command\Utility;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MCP\DataType\Time\Clock;
use Predis\Client as Predis;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Server;
use QL\Hal\Core\Utility\SortingTrait;
use QL\Hal\Agent\Command\CommandTrait;
use QL\Hal\Agent\Command\FormatterTrait;
use QL\Hal\Agent\Push\HostnameValidatorTrait;
use QL\Hal\Agent\Remoting\SSHSessionManager;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
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
class VerifyServerConnectionsCommand extends Command implements OutputAwareInterface
{
    use CommandTrait;
    use FormatterTrait;
    use HostnameValidatorTrait;
    use OutputAwareTrait;
    use SortingTrait;

    const REDIS_KEY = 'agent-status:server';
    const REDIS_LIST_SIZE = 20;

    /**
     * This is manually built so we can support incremental table rendering
     */
    const TABLE_HEADER = <<<STDOUT
| Environment | Status   | Hostname                                            |                                       |
STDOUT;
    const TABLE_ROW = <<<STDOUT
| %s | %s | %s | %s |
STDOUT;
    const TABLE_SEPARATOR = <<<STDOUT
+-------------+----------+-----------------------------------------------------+---------------------------------------+
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
        2 => 'No servers found.'
    ];

    /**
     * @type EntityRepository
     */
    private $serverRepo;
    private $environmentRepo;

    /**
     * @type SSHSessionManager
     */
    private $sshManager;

    /**
     * @type Predis
     */
    private $predis;

    /**
     * @type Clock
     */
    private $clock;

    /**
     * @type string
     */
    private $remoteUser;

    /**
     * @param string $name
     * @param EntityManagerInterface $em
     * @param SSHSessionManager $sshManager
     * @param Predis $predis
     * @param Clock $clock
     * @param string $remoteUser
     */
    public function __construct(
        $name,
        EntityManagerInterface $em,
        SSHSessionManager $sshManager,
        Predis $predis,
        Clock $clock,
        $remoteUser
    ) {
        parent::__construct($name);

        $this->serverRepo = $em->getRepository(Server::CLASS);
        $this->environmentRepo = $em->getRepository(Environment::CLASS);
        $this->sshManager = $sshManager;
        $this->predis = $predis;
        $this->clock = $clock;

        $this->remoteUser = $remoteUser;
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
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setOutput($output);

        $res = $this->getServers($input->getArgument('ENVIRONMENT_NAME'));
        if (is_int($res)) {
            return $this->failure($output, $res);
        }

        list($environment, $servers) = $res;

        $this->displayMeta($environment);

        $serverByEnv = $this->sortServersIntoEnvironments($servers);
        $statuses = $this->testConnections($serverByEnv);

        // Only send to redis if checking status of all servers
        if (!$environment) {
            $this->sendToRedis($statuses);
        }

        return $this->finish($output, 0);
    }

    /**
     * @param string $string
     * @param string $status
     * @param string $hostname
     * @param string $detail
     *
     * @return string
     */
    private function buildRow($env, $status, $hostname, $detail)
    {
        $row = sprintf(
            self::TABLE_ROW,
            str_pad($env, 12),
            str_pad($status, 6),
            str_pad($hostname, 40),
            str_pad($detail, 40)
        );

        return $row;
    }

    /**
     * @return string
     */
    private function buildDivider()
    {
        return $this->buildRow(
            str_repeat('-', 12),
            str_repeat('-', 6),
            str_repeat('-', 40),
            str_repeat('-', 40)
        );
    }

    /**
     * @param Environment|null $environment
     *
     * @return void
     */
    private function displayMeta(Environment $environment = null)
    {
        $this->getOutput()->writeln('');
        $this->getOutput()->writeln(sprintf('Connecting as user: <comment>%s</comment>', $this->remoteUser));

        if ($environment) {
            $this->getOutput()->writeln(sprintf('Environment: <comment>%s</comment>', $environment->name()));
        }
    }

    /**
     * @return void
     */
    private function displayTableHeader()
    {
        $divider = $this->buildDivider();

        $row = $this->buildRow(
            'Environment',
            'Status',
            'Hostname',
            'Detail'
        );

        $this->getOutput()->writeln('');
        $this->getOutput()->writeln($row);
        $this->getOutput()->writeln($divider);
    }

    /**
     * @param string $env
     *
     * @return array|int
     */
    private function getServers($env)
    {
        $environment = null;
        if ($env && !$environment = $this->environmentRepo->findOneBy(['name' => strtolower($env)])) {
            return 1;
        }

        $servers = ($environment) ? $this->serverRepo->findBy(['environment' => $environment]) : $this->serverRepo->findAll();

        if (!$servers) {
            return 2;
        }

        return [$environment, $servers];
    }

    /**
     * @param array $environments
     *
     * @return array
     */
    public function testConnections(array $environments)
    {
        $statuses = [];

        $this->displayTableHeader();

        $blankDivider = $this->buildRow('', '', '', '');

        foreach ($environments as $env) {
            foreach ($env as $server) {

                // Skip non-rsync
                if ($server->type() !== 'rsync') {
                    $this->displayRow(
                        $server->environment()->name(),
                        null,
                        $server->type(),
                        ''
                    );

                    continue;
                }

                $status = $this->checkServerStatus($server);
                $statuses[$server->id()] = $status;

                $this->displayRow($status['environment'], $status['status'], $status['server'], $status['detail']);
            }

            $this->getOutput()->writeln($blankDivider);
        }

        return $statuses;
    }

    /**
     * @param Server $server
     *
     * @return array
     */
    private function checkServerStatus(Server $server)
    {
        $serverName = $server->name();

        // Slice off port if provided
        $serverName = strtok($serverName, ':');
        $port = strtok(':');

        $detail = '';

        if (!$host = $this->validateHostname($serverName)) {
            $detail = 'Cannot resolve hostname.';
            $status = false;
        } else {

            if ($port !== false) {
                $host .= sprintf(':%d', $port);
            }

            if ($server->name() !== $host) {
                $detail = sprintf('Resolved: %s', $host);
            }

            $status = $this->attemptConnection($host);
            if (is_array($status)) {
                $detail = implode($status, ' ');
                $status = false;
            }
        }

        return [
            'environment' => $server->environment()->name(),
            'server' => $server->name(),
            'status' => $status,
            'detail' => $detail
        ];
    }

    /**
     * @param string $serverName
     *
     * @return bool|array
     */
    private function attemptConnection($serverName)
    {
        if (!$serverName) {
            return false;
        }

        $session = $this->sshManager->createSession($this->remoteUser, $serverName);
        $errors = $this->sshManager->getErrors();

        if ($session) {
            $this->sshManager->disconnectAll();
            return true;
        }

        return $errors;
    }

    /**
     * @param string $env
     * @param bool|null $status
     * @param string $hostname
     * @param string $detail
     *
     * @return void
     */
    private function displayRow($env, $status, $hostname, $detail)
    {
        if ($status === null) {
            $display = '<comment>SKIP</comment>';
        } else {
            $display = $status ? '<info>PASS</info>' : '<error>FAIL</error>';
        }

        $display = sprintf('[%s]', $display);

        $row = $this->buildRow($env, $display, $hostname, $detail);

        $this->getOutput()->writeln($row);
    }

    /**
     * @param Server[] $servers
     *
     * @return array
     */
    private function sortServersIntoEnvironments(array $servers)
    {
        $environments = [];

        foreach ($servers as $server) {
            $environments[$server->environment()->id()] = $server->environment();
        }

        $environments = array_values($environments);
        usort($environments, $this->environmentSorter());

        $serverByEnv = [];
        foreach ($environments as $environment) {
            $serverByEnv[$environment->name()] = [];
        }

        // Add servers to groupings
        foreach ($servers as $server) {
            $envName = $server->environment()->name();

            if (!array_key_exists($envName, $serverByEnv)) {
                $serverByEnv[$envName] = [];
            }

            $serverByEnv[$envName][] = $server;
        }

        // Sort servers within env
        $sorter = $this->serverSorter();
        foreach ($serverByEnv as &$env) {
            usort($env, $sorter);
        }

        return $serverByEnv;
    }

    /**
     * @param array $statuses
     *
     * @return void
     */
    private function sendToRedis(array $statuses)
    {
        $data = [
            'servers' => $statuses,
            'generated' => $this->clock->read()->format(DateTime::ISO8601, 'UTC'),
            'generated_by' => gethostname()
        ];

        $json = json_encode($data);

        // push onto list
        $this->predis->lpush(self::REDIS_KEY, $json);

        // trim the list to last 10 items
        $this->predis->ltrim(self::REDIS_KEY, 0, self::REDIS_LIST_SIZE);
    }
}
