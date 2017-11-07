<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Unix;

class DockerBuilderConfigurator
{
    /**
     * @var bool
     */
    private $dockerSudo;
    private $dockerDebugMode;

    public function __construct(bool $useDockerSudo, bool $dockerDebugMode)
    {
        $this->dockerSudo = $useDockerSudo;
        $this->dockerDebugMode = $dockerDebugMode;
    }

    /**
     * @param DockerBuilder $builder
     */
    public function configure(DockerBuilder $builder)
    {
        if ($this->dockerSudo) {
            $builder->enableDockerSudo();
        }

        if ($this->dockerDebugMode) {
            $builder->enableDockerCommandLogging();
        }
    }
}
