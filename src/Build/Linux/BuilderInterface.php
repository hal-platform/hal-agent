<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux;

interface BuilderInterface
{
    /**
     * @param string $imageName
     *
     * @param string $remoteConnection
     * @param string $remoteFile
     *
     * @param array $commands
     * @param array $env
     *
     * @return boolean
     */
    public function __invoke(string $imageName, string $remoteConnection, string $remoteFile, array $commands, array $env);
}
