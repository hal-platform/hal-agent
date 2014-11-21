<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Testing;

use JsonSerializable;

class JsonableStub implements JsonSerializable
{
    public $data;

    public function jsonSerialize()
    {
        return $this->data;
    }
}
