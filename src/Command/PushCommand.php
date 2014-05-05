<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Psr\Log\LoggerInterface;
use QL\Hal\Agent\Push\Resolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *  Push a previously built application to a server.
 */
class PushCommand extends Command
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Resolver
     */
    private $resolver;

    /**
     * @var boolean
     */
    private $debugMode;

    /**
     * @param string $name
     * @param boolean $debugMode
     * @param LoggerInterface $logger
     * @param Resolver $resolver
     */
    public function __construct(
        $name,
        $debugMode,
        LoggerInterface $logger,
        Resolver $resolver
    ) {
        parent::__construct($name);
        $this->debugMode = $debugMode;

        $this->logger = $logger;
        $this->resolver = $resolver;
    }

    /**
     *  Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Deploy a previously built application to a server.')
            ->addArgument(
                'BUILD_ID',
                InputArgument::REQUIRED,
                'The ID of the build to deploy.'
            )
            ->addArgument(
                'DEPLOYMENT_ID',
                InputArgument::REQUIRED,
                'The ID of the deployment relationship.'
            )
            ->addArgument(
                'METHOD',
                InputArgument::OPTIONAL,
                'The deployment method to use.',
                'rsync'
            );
    }

    /**
     *  Run the command
     *
     *  @param InputInterface $input
     *  @param OutputInterface $output
     *  @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $buildId = $input->getArgument('BUILD_ID');
        $deployId = $input->getArgument('DEPLOYMENT_ID');
        $method = $input->getArgument('METHOD');

        // resolve
        $output->writeln('<comment>Resolving...</comment>');
        if (!$properties = call_user_func($this->resolver, $buildId, $deployId, $method)) {
            $this->error($output, 'Deployment details could not be resolved.');
            return 1;
        }


    }
}
