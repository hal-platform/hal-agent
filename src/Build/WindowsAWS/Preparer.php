<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\Ssm\SsmClient;
use Hal\Agent\Build\InternalDebugLoggingTrait;
use Hal\Agent\Build\WindowsAWS\AWS\SSMCommandRunner;
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;

class Preparer
{
    use InternalDebugLoggingTrait;

    const TIMEOUT_INTERNAL_COMMAND = 120;

    /**
     * @var SSMCommandRunner
     */
    private $runner;

    /**
     * @var Powershellinator
     */
    private $powershell;

    /**
     * @param SSMCommandRunner $runner
     * @param Powershellinator $powershell
     */
    public function __construct(SSMCommandRunner $runner, Powershellinator $powershell)
    {
        $this->runner = $runner;
        $this->powershell = $powershell;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @return bool
     */
    public function __invoke(SsmClient $ssm, $instanceID)
    {
        $runner = $this->runner;
        $result = $runner($ssm, $instanceID, SSMCommandRunner::TYPE_POWERSHELL, [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('verifyAndPrepareBuilder')
            ],
            'executionTimeout' => [(string) self::TIMEOUT_INTERNAL_COMMAND],
        ], [$this->isDebugLoggingEnabled()]);

        return $result;
    }
}
