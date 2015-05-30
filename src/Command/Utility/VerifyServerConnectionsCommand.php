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
    use SortingHelperTrait;

    const REDIS_KEY = 'agent-status:server';

    /**
     * This is manually built so we can support incremental table rendering
     */
    const TABLE_HEADER = <<<STDOUT
+-------------+----------+-----------------------------------------------------+
| Environment | Status   | Hostname                                            |
+-------------+----------+-----------------------------------------------------+
STDOUT;
    const TABLE_ROW = <<<STDOUT
| %s | %s   | %s |
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
            return $this->failure($output, 2);
        }

        $output->writeln('');
        $output->writeln(sprintf('Connecting as user: <comment>%s</comment>', $this->remoteUser));
        if ($environment) {
            $output->writeln(sprintf('Environment: <comment>%s</comment>', $environment->getKey()));
        }

        $environments = $this->sortServersIntoEnvironments($servers);
        $statuses = [];

        $output->write(self::TABLE_HEADER, true);
        foreach ($environments as $env) {
            foreach ($env as $server) {
                $envName = $server->getEnvironment()->getKey();
                if ($server->getType() !== 'rsync') {
                    $row = $this->buildRow($envName, sprintf('type: %s', $server->getType()));
                    $output->writeln($row);
                    continue;
                }

                $serverName = $server->getName();
                $resolved = $this->validateHostname($serverName);

                if ($resolved === null) {
                    $success = false;
                    $serverName = sprintf('cannot_resolve: %s', $serverName);

                } else {
                    $success = $this->attemptConnection($resolved);
                    if ($serverName !== $resolved) {
                        $serverName = sprintf('%s -> %s', $serverName, $resolved);
                    }
                }

                $statuses[$server->getId()] = [
                    'server' => $serverName,
                    'environment' => $envName,
                    'status' => $success
                ];

                $row = $this->buildRow($envName, $serverName, $success);
                $output->writeln($row);
            }

            $output->writeln(self::TABLE_SEPARATOR);
        }

        $this->sendToRedis([
            'servers' => $statuses,
            'generated' => $this->clock->read()->format(DateTime::ISO8601, 'UTC')
        ]);

        return $this->finish($output, 0);
    }

    /**
     * @param string $serverName
     *
     * @return bool
     */
    private function attemptConnection($serverName)
    {
        if (!$serverName) {
            return false;
        }

        $session = $this->sshManager->createSession($this->remoteUser, $serverName);
        if ($session) {
            $this->sshManager->disconnectAll();
            return true;
        }

        return false;
    }

    /**
     * @param string $env
     * @param string $hostname
     * @param bool|null $status
     *
     * @return string
     */
    private function buildRow($env, $hostname, $status = null)
    {
        if ($status === null) {
            $display = '<comment>SKIP</comment>';
        } else {
            $display = $status ? '<info>PASS</info>' : '<error>FAIL</error>';
        }

        $display = sprintf('[%s]', $display);

        return sprintf(
            self::TABLE_ROW,
            str_pad($env, 11),
            $display,
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
        uasort($environments, $this->envSorter());

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
        $sortOrder = [
            'dev' => 0,
            'test' => 1,
            'beta' => 2,
            'prod' => 3
        ];

        return function($a, $b) use ($sortOrder) {

            $firstA = reset($a);
            $firstB = reset($b);

            $aName = $firstA->getName();
            $bName = $firstB->getName();

            $aOrder = isset($sortOrder[$aName]) ? $sortOrder[$aName] : 999;
            $bOrder = isset($sortOrder[$bName]) ? $sortOrder[$bName] : 999;

            if ($aOrder === $bOrder) {
                return 0;
            }

            return ($aOrder > $bOrder);

        };
    }

    /**
     * @param array $data
     *
     * @return void
     */
    private function sendToRedis(array $data)
    {
        $json = json_encode($data);

        // push onto list
        $this->predis->lpush(self::REDIS_KEY, $json);

        // trim the list to last 10 items
        $this->predis->ltrim(self::REDIS_KEY, 0, 10);
    }
}
