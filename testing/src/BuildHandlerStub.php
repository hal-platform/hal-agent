<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Testing;

use QL\Hal\Agent\Build\BuildHandlerInterface;

class BuildHandlerStub implements BuildHandlerInterface
{
    public $response;

    public function __invoke(array $commands, array $properties)
    {
        return $this->response;
    }
}
