<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\Script;

use Hal\Agent\Build\DelegatingBuilder;

class DeployDelegatingBuilder extends DelegatingBuilder
{
    const PREPARING_BUILD_ENVIRONMENT = 'Prepare deployment environment';
    const ERR_INVALID_BUILDER = 'Invalid deployment system specified';
}
