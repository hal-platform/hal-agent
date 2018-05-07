<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Parameters;

trait EnvironmentVariablesTrait
{
    /**
     * @param array $env              Configuration provided by Hal
     * @param array $decrypted        Configuration decrypted
     * @param array $configurationEnv Configuration provided in configuration file
     *
     * @return array
     */
    public function determineEnvironment(array $env, array $decrypteds, $configurationEnv)
    {
        if ($decrypteds) {
            foreach ($decrypteds as $property => $decrypted) {
                $key = sprintf('ENCRYPTED_%s', strtoupper($property));
                $env[$key] = $decrypted;
            }
        }

        return $this->mergeUserProvidedEnv($env, $configurationEnv);
    }

    /**
     * - env overrides global
     * - global overrides encrypted or hal-specified config
     *
     * @param array $env
     * @param array $configurationEnv
     *
     * @return string
     */
    private function mergeUserProvidedEnv(array $env, array $configurationEnv)
    {
        $localEnv = [];

        $globals = $configurationEnv['global'] ?? [];
        foreach ($globals as $name => $value) {
            $localEnv[$name] = $value;
        }

        $targetEnv = $env['HAL_ENVIRONMENT'] ?? '';

        $specific = $configurationEnv[$targetEnv] ?? [];
        foreach ($specific as $name => $value) {
            $localEnv[$name] = $value;
        }

        return $env + $localEnv;
    }

    /**
     * @param Job $job
     *
     * @return array
     */
    private function buildEnvironmentVariables(Job $job): array
    {
        $environmentName = 'None';
        $applicationName = 'None';

        $vcsCommit = '';
        $vcsReference = '';

        $context = '';

        if ($job instanceof Build || $job instanceof Release) {
            $environmentName = ($environment = $job->environment()) ? $environment->name() : 'None';
            $applicationName = ($application = $job->application()) ? $application->name() : 'None';
        }

        if ($job instanceof Build) {
            $vcsCommit = $job->commit();
            $vcsReference = $job->reference();

        } elseif ($job instanceof Release && $build = $job->build()) {
            $vcsCommit = $build->commit();
            $vcsReference = $build->reference();

            if ($target = $job->target()) {
                $context = $target->parameter(Parameters::TARGET_CONTEXT) ?: '';
            }
        }

        $env = [
            'HAL_JOB_ID' => $job->id(),
            'HAL_JOB_TYPE' => $job->type(),

            'HAL_VCS_COMMIT' => $vcsCommit,
            'HAL_VCS_REF' => $vcsReference,

            'HAL_ENVIRONMENT' => $environmentName,
            'HAL_APPLICATION' => $applicationName,

            'HAL_CONTEXT' => $context,
            // 'HAL_DEPLOY_PLATFORM' => $platform,

        ];

        return $env;
    }
}
