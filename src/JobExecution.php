<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent;

/**
 * Execution context for a Job.
 *
 * This should contain references to the platform/stage of a job, and the configuration for it.
 */
class JobExecution
{
    /**
     * @var string
     */
    private $platform;
    private $stage;

    /**
     * @var array
     */
    private $config;

    /**
     * @param string $platform
     * @param string $stage
     * @param array $config
     */
    public function __construct(string $platform, string $stage, array $config)
    {
        $this->platform = $platform;
        $this->stage = $stage;

        $this->config = $config;
    }

    /**
     * @return string
     */
    public function platform(): string
    {
        return $this->platform;
    }

    /**
     * @return string
     */
    public function stage(): string
    {
        return $this->stage;
    }

    /**
     * TODO eventually this should validate configuration?
     *
     * @return array
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * @return array
     */
    public function steps(): array
    {
        return $this->config[$this->stage] ?? [];
    }

    /**
     * @return mixed
     */
    public function parameter(string $name)
    {
        return $this->config[$name] ?? null;
    }
}
