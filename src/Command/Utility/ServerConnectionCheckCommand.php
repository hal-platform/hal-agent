<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command\Utility;

use QL\Hal\Agent\Remoting\FileSyncManager;
use QL\Hal\Agent\Github\ArchiveApi;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Check if the agent can connect to servers
 *
 * BUILT FOR COMMAND LINE ONLY
 */
class ServerConnectionCheckCommand extends Command
{

}
