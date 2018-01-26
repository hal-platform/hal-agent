<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

trait EnvironmentVariablesTrait
{
    /**
     * @param array $env              Configuration provided by Hal
     * @param array $decrypted        Configuration decrypted
     * @param array $configurationEnv Configuration provided in configuration file
     *
     * @return array
     */
    public function determineEnviroment(array $env, array $decrypteds, $configurationEnv)
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

        if (isset($configurationEnv['global'])) {
            foreach ($configurationEnv['global'] as $name => $value) {
                $localEnv[$name] = $value;
            }
        }

        $targetEnv = isset($env['HAL_ENVIRONMENT']) ? $env['HAL_ENVIRONMENT'] : '';

        if (isset($configurationEnv[$targetEnv])) {
            foreach ($configurationEnv[$targetEnv] as $name => $value) {
                $localEnv[$name] = $value;
            }
        }

        return $env + $localEnv;
    }
}
