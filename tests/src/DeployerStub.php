<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Testing;

use Hal\Agent\Push\DeployerInterface;

class DeployerStub implements DeployerInterface
{
    public $response;

    public function __invoke(array $properties)
    {
        return $this->response;
    }
}
