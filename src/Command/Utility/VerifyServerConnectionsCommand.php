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
class VerifyServerCommandsCommand extends Command
{
    use CommandTrait;
    use FormatterTrait;
    use SortingHelperTrait;

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

        if ($environmentName && !$environment = $this->environmentRepo->findOneBy(['name' => strtolower($environmentName)])) {
            return $this->failure($output, 1);
        }

        if ($environment) {
            $servers = $this->serverRepo->findBy(['environment' => $environment]);
        } else {
            $servers = $this->serverRepo->findAll();
        }

        if (!$servers) {
            $this->status($output, 'No servers to check?');
        }

        $environments = $this->sortServersIntoEnvironments($servers);

        if (false) {
            return $this->failure($output, 1);
        }

        return $this->success($output, 'Success!');
    }

    /**
     * @param Server[] $servers
     *
     * @return array
     */
    private function sortServersIntoEnvironments(array $servers)
    {

    }


    /**
     * @param Deployment[] $deployments
     * @return array
     */
    private function environmentalizeDeployments(array $deployments)
    {
        // should be using server.order instead
        $environments = [
            'dev' => [],
            'test' => [],
            'beta' => [],
            'prod' => []
        ];

        foreach ($deployments as $deployment) {
            $env = $deployment->getServer()->getEnvironment()->getKey();

            if (!array_key_exists($env, $environments)) {
                $environments[$env] = [];
            }

            $environments[$env][] = $deployment;
        }

        $sorter = $this->deploymentSorter();
        foreach ($environments as &$env) {
            usort($env, $sorter);
        }

        return $environments;
    }
}
