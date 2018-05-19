<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux\Steps;

use Hal\Agent\Build\EnvironmentVariablesTrait;
use Hal\Core\Entity\Job;
use QL\MCP\Common\GUID;

class Configurator
{
    use EnvironmentVariablesTrait;

    /**
     * @param Job $job
     *
     * @return array
     */
    public function __invoke(Job $job)
    {
        return [
            'stage_id' => 'stage-' . GUID::create()->format(GUID::HYPHENATED),
            'environment_variables' => $this->buildEnvironmentVariables($job)
        ];
    }
}
