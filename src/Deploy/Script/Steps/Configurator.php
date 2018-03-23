<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\Script\Steps;

use Hal\Agent\JobExecution;

class Configurator
{
    /**
     * @param JobExecution $jobExecution
     *
     * @return array|null
     */
    public function __invoke(JobExecution $jobExecution): ?array
    {
        $config = $jobExecution->config();
        if (!$config) {
            return null;
        }

        $scriptExecution = new JobExecution(
            $config['platform'],
            $jobExecution->stage(),
            $config
        );

        return [
            'platform' => $config['platform'],
            'scriptExecution' => $scriptExecution
        ];
    }
}
