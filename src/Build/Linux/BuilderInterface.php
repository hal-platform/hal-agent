<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux;

use Hal\Agent\Symfony\IOAwareInterface;

interface BuilderInterface extends IOAwareInterface
{
    /**
     * @param string $jobID
     * @param string $image
     * @param string $stagePath
     * @param array $steps
     * @param array $env
     *
     * @return bool
     */
    public function __invoke(string $jobID, string $image, string $stagePath, array $steps, array $env): bool;
}
