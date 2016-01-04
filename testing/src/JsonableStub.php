<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
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
