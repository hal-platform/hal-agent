<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Command\Docker;

use DateTime;
use MCP\DataType\Time\Clock;
use Net_SSH2;
use Predis\Client as Predis;
use QL\Hal\Agent\Command\CommandTrait;
use QL\Hal\Agent\Command\FormatterTrait;
use QL\Hal\Agent\Remoting\SSHSessionManager;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Get the status of docker containers and images
 * - docker ps -a
 * - docker images
 *
 * BUILT FOR COMMAND LINE ONLY
 */
class CheckStatusCommand extends Command implements OutputAwareInterface
{
    use CommandTrait;
    use FormatterTrait;
    use OutputAwareTrait;

    const REDIS_KEY = 'agent-status:docker';
    const REDIS_LIST_SIZE = 20;

    const TIMEOUT = 10;
    const DOCKER_DIR = '/var/lib/docker';

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
        1 => 'Failed to initiate session with build server.',
    ];

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
    private $unixBuildUser;
    private $unixBuildServer;
    private $localTemp;
    private $buildTemp;

    /**
     * @type bool
     */
    private $useSudoForDocker;

    /**
     * @param string $name
     * @param SSHSessionManager $sshManager
     * @param Predis $predis
     * @param Clock $clock
     *
     * @param string $unixBuildUser
     * @param string $unixBuildServer
     * @param bool $useSudoForDocker
     *
     * @param string $localTemp
     * @param string $buildTemp
     */
    public function __construct(
        $name,
        SSHSessionManager $sshManager,
        Predis $predis,
        Clock $clock,

        $unixBuildUser,
        $unixBuildServer,
        $useSudoForDocker,

        $localTemp,
        $buildTemp
    ) {
        parent::__construct($name);

        $this->sshManager = $sshManager;
        $this->predis = $predis;
        $this->clock = $clock;

        $this->unixBuildUser = $unixBuildUser;
        $this->unixBuildServer = $unixBuildServer;
        $this->useSudoForDocker = $useSudoForDocker;

        $this->localTemp = $localTemp;
        $this->buildTemp = $buildTemp;
    }

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Get the status of docker containers and images.')
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

        $session = $this->sshManager->createSession($this->unixBuildUser, $this->unixBuildServer);
        if (!$session) {
            return $this->failure($output, 1);
        }

        $session->setTimeout(self::TIMEOUT);

        $agent = $this->checkAgent();
        $builder = $this->checkBuilder($session);
        $docker = $this->checkDocker($session);

        $this->sshManager->disconnectAll();

        $this->status('System', 'Agent');
        $output->writeln($agent['system']);

        $this->status('Temp', 'Agent');
        $output->writeln($agent['temp']);

        $this->status('System', 'Builder');
        $output->writeln($builder['system']);

        $this->status('Temp', 'Builder');
        $output->writeln($builder['temp']);

        $this->status('Docker', 'Builder');
        $output->writeln($builder['docker']);

        $this->status('Info', 'Docker');
        $output->writeln($docker['info']);

        $this->status('Images', 'Docker');
        $output->writeln($docker['images']);

        $this->status('Containers', 'Docker');
        $output->writeln($docker['containers']);

        $this->sendToRedis([
            'agent' => $agent,
            'builder' => $builder,
            'docker' => $docker,
            'generated' => $this->clock->read()->format(DateTime::ISO8601, 'UTC'),
            'generated_by' => gethostname()
        ]);

        return 0;
    }

    /**
     * @return string
     */
    private function checkAgent()
    {
        return [
            'system' => $this->local('df -h'),
            'temp' => $this->local(sprintf('du -sh %s', $this->localTemp))
        ];
    }

    /**
     * @param Net_SSH2 $ssh
     *
     * @return string
     */
    private function checkBuilder(Net_SSH2 $ssh)
    {
        $sudo = $this->useSudoForDocker ? 'sudo ' : '';

        return [
            'system' => $this->remote($ssh, 'df -h'),
            'temp' => $this->remote($ssh, sprintf('du -sh %s', $this->buildTemp)),
            'docker' => $this->remote($ssh, $sudo . sprintf('du -sh %s', self::DOCKER_DIR))
        ];
    }

    /**
     * @param Net_SSH2 $ssh
     *
     * @return string
     */
    private function checkDocker(Net_SSH2 $ssh)
    {
        return [
            'info' => $this->remote($ssh, $this->docker() . ' info'),
            'images' => $this->remote($ssh, $this->docker() . ' images'),
            'containers' => $this->remote($ssh, $this->docker() . ' ps -as')
        ];
    }

    /**
     * @param string $command
     *
     * @return string
     */
    private function local($command)
    {
        // cause im lazy, shut up
        exec($command, $output);

        return trim(implode("\n", $output));
    }

    /**
     * @param Net_SSH2 $ssh
     * @param string $command
     *
     * @return string
     */
    private function remote(Net_SSH2 $ssh, $command)
    {
        $output = $ssh->exec($command);

        if ($error = $ssh->getStdError()) {
            $output .= $error;
        }

        return trim($output);
    }

    /**
     * @return string
     */
    private function docker()
    {
        if ($this->useSudoForDocker) {
            return 'sudo docker';
        }

        return 'docker';
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
        $this->predis->ltrim(self::REDIS_KEY, 0, self::REDIS_LIST_SIZE);
    }
}
