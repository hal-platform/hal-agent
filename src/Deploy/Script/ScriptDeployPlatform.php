<?php
/**
 * @copyright (c) 2018 Steve Kluck
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\Script;

use Hal\Agent\Deploy\PlatformTrait;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Deploy\Script\Steps\Configurator;
use Hal\Agent\JobPlatformInterface;
use Hal\Agent\JobExecution;
use Hal\Agent\JobRunner;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\IOAwareInterface;
use Hal\Agent\Symfony\IOAwareTrait;
use Hal\Core\Entity\Job;

class ScriptDeployPlatform implements IOAwareInterface, JobPlatformInterface
{
    use FormatterTrait;
    // Comes with IOAwareTrait
    use PlatformTrait;

    private const STEP_1_CONFIGURING = 'Script Platform - Validating script configuration';
    private const STEP_2_EXECUTING = 'Script Platform - Executing script';

    private const ERR_CONFIGURATOR = 'Script deploy platform is not configured correctly';
    private const ERR_EXECUTOR = 'There was an error executing the script';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Configurator
     */
    private $configurator;

    /**
     * @var JobRunner
     */
    private $jobRunner;

    /**
     * @param EventLogger $logger
     */
    public function __construct(
        EventLogger $logger,
        Configurator $configurator,
        JobRunner $jobRunner
    ) {
        $this->logger = $logger;
        $this->configurator = $configurator;
        $this->jobRunner = $jobRunner;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(Job $job, JobExecution $execution, array $properties): bool
    {
        if (!$platformConfiguration = $this->configurator($execution)) {
            $this->sendFailureEvent(self::ERR_CONFIGURATOR);
            return false;
        }

        if (!$this->executor($job, $platformConfiguration, $properties)) {
            $this->sendFailureEvent(self::ERR_EXECUTOR);
            return false;
        }

        return true;
    }

    /**
     * @param JobExecution $execution
     *
     * @return array|null
     */
    private function configurator(JobExecution $execution)
    {
        $this->getIO()->section(self::STEP_1_CONFIGURING);

        $platformConfiguration = ($this->configurator)($execution);

        if (!$platformConfiguration) {
            return null;
        }

        $this->getIO()->listing([
            sprintf('Platform: %s', $platformConfiguration['platform'])
        ]);

        return $platformConfiguration;
    }

    /**
     * @param Job $job
     * @param array $configuration
     * @param array $properties
     *
     * return bool
     */
    private function executor(Job $job, array $configuration, array $properties)
    {
        $scriptExecution = $configuration['scriptExecution'];

        $this->getIO()->section(self::STEP_2_EXECUTING);
        $this->getIO()->listing($scriptExecution->steps());

        return ($this->jobRunner)($job, $this->getIO(), $scriptExecution, $properties);
    }

    /**
     * @param string $message
     *
     * @return void
     */
    private function sendFailureEvent($message)
    {
        $this->logger->event('failure', $message);
        $this->getIO()->error($message);
    }
}
