<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Unix;

class DockerBuilderConfigurator
{
    /**
     * @type bool
     */
    private $dockerSudo;
    private $dockerDebugMode;

    public function __construct($useDockerSudo, $dockerDebugMode)
    {
        $this->dockerSudo = $useDockerSudo;
        $this->dockerDebugMode = $dockerDebugMode;
    }

    /**
     * @param DockerBuilder $builder
     */
    public function configure($builder)
    {
        if ($this->dockerSudo) {
            $builder->enableDockerSudo();
        }

        if ($this->dockerDebugMode) {
            $builder->enableDockerCommandLogging();
        }
    }
}
