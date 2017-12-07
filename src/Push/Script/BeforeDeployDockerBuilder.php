<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\Script;

use Hal\Agent\Build\Unix\DockerBuilder;

class BeforeDeployDockerBuilder extends DockerBuilder
{
    const SECTION_BUILD = 'Docker - Before Deploy';

    const EVENT_MESSAGE = 'Run before deploy command';
    const EVENT_MESSAGE_CUSTOM = 'Run before deploy command "%s"';

    const ERR_MESSAGE_SKIPPING = 'Skipping %s remaining before deploy commands';
}
