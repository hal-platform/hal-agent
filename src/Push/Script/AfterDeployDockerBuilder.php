<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\Script;

use Hal\Agent\Build\Unix\DockerBuilder;

class AfterDeployDockerBuilder extends DockerBuilder
{
    const SECTION_BUILD = 'Docker - After Deploy';

    const EVENT_MESSAGE = 'Run after deploy command';
    const EVENT_MESSAGE_CUSTOM = 'Run after deploy command "%s"';
}
