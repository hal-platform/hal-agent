<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Symfony\IOAwareTrait;

trait PlatformTrait
{
    use EmergencyBuildHandlerTrait;
    use EnvironmentVariablesTrait;
    use IOAwareTrait;
}
