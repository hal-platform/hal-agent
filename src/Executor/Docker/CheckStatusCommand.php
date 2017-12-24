<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Docker;

use DateTime;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use Hal\Agent\Remoting\SSHSessionManager;
use phpseclib\Net\SSH2;
use Predis\Client as Predis;
use QL\MCP\Common\Time\Clock;
use Symfony\Component\Console\Command\Command;

/**
 * Get the status of docker containers and images
 * - docker ps -a
 * - docker images
 */
class CheckStatusCommand implements ExecutorInterface
{
    use ExecutorTrait;
    use FormatterTrait;

    const STEPS = [];

    const COMMAND_TITLE = 'Docker - Check Status';

    const REDIS_KEY = 'agent-status:docker';
    const REDIS_LIST_SIZE = 20;

    const TIMEOUT = 10;
    const DOCKER_DIR = '/var/lib/docker';

    const ERR_SESSION = 'Failed to initiate session with build server.';

    /**
     * @var SSHSessionManager
     */
    private $sshManager;

    /**
     * @var Predis
     */
    private $predis;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var string
     */
    private $unixBuildUser;
    private $unixBuildServer;
    private $localTemp;
    private $buildTemp;

    /**
     * @var bool
     */
    private $useSudoForDocker;

    /**
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
        SSHSessionManager $sshManager,
        Predis $predis,
        Clock $clock,
        //
        string $unixBuildUser,
        string $unixBuildServer,
        bool $useSudoForDocker,
        //
        string $localTemp,
        string $buildTemp
    ) {
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
     * @param Command $command
     *
     * @return void
     */
    public static function configure(Command $command)
    {
        $command
            ->setDescription('Get the status of docker containers and images.');
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $io->title(self::COMMAND_TITLE);

        $session = $this->sshManager->createSession($this->unixBuildUser, $this->unixBuildServer);
        if (!$session) {
            return $this->failure($io, self::ERR_SESSION);
        }

        $session->setTimeout(self::TIMEOUT);

        $agent = $this->checkAgent();
        $builder = $this->checkBuilder($session);
        $docker = $this->checkDocker($session);

        $this->sshManager->disconnectAll();

        $io->section('Agent');
        foreach (['system', 'temp'] as $info) {
            $io->text(sprintf("<info>%s</info>\n%s\n", ucfirst($info), $agent[$info]));
        }

        $io->section('Builder');
        foreach (['system', 'temp', 'docker'] as $info) {
            $io->text(sprintf("<info>%s</info>\n%s\n", ucfirst($info), $builder[$info]));
        }

        $io->section('Docker');
        foreach (['info', 'images', 'containers'] as $info) {
            $io->text(sprintf("<info>%s</info>\n%s\n", ucfirst($info), $docker[$info]));
        }

        $this->sendToRedis([
            'agent' => $agent,
            'builder' => $builder,
            'docker' => $docker,
            'generated' => $this->clock->read()->format(DateTime::ISO8601, 'UTC'),
            'generated_by' => gethostname()
        ]);

        return $this->success($io);
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
     * @param SSH2 $ssh
     *
     * @return string
     */
    private function checkBuilder(SSH2 $ssh)
    {
        $sudo = $this->useSudoForDocker ? 'sudo ' : '';

        return [
            'system' => $this->remote($ssh, 'df -h'),
            'temp' => $this->remote($ssh, sprintf('du -sh %s', $this->buildTemp)),
            'docker' => $this->remote($ssh, $sudo . sprintf('du -sh %s', self::DOCKER_DIR))
        ];
    }

    /**
     * @param SSH2 $ssh
     *
     * @return string
     */
    private function checkDocker(SSH2 $ssh)
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
     * @param SSH2 $ssh
     * @param string $command
     *
     * @return string
     */
    private function remote(SSH2 $ssh, $command)
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
