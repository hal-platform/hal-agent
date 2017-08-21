<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Testing;

use Hal\Agent\Build\BuildHandlerInterface;

class BuildHandlerStub implements BuildHandlerInterface
{
    public $response;

    public function __invoke(array $commands, array $properties)
    {
        return $this->response;
    }
}
