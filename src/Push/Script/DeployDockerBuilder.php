<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\Script;

use Hal\Agent\Build\Unix\DockerBuilder;

class DeployDockerBuilder extends DockerBuilder
{
    const SECTION_BUILD = 'Docker - Deploy';

    const EVENT_MESSAGE = 'Run deploy command';
    const EVENT_MESSAGE_CUSTOM = 'Run deploy command "%s"';
}
