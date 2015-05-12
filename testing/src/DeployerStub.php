<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Testing;

use QL\Hal\Agent\Push\DeployerInterface;

class DeployerStub implements DeployerInterface
{
    public $response;

    public function __invoke(array $properties)
    {
        return $this->response;
    }
}
