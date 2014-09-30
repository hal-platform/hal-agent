<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Doctrine\ORM\EntityManager;
use MCP\DataType\Time\Clock;
use QL\Hal\Agent\Build\Builder;
use QL\Hal\Agent\Build\Downloader;
use QL\Hal\Agent\Build\Packer;
use QL\Hal\Agent\Build\Resolver;
use QL\Hal\Agent\Build\Unpacker;
use QL\Hal\Agent\Helper\DownloadProgressHelper;
use QL\Hal\Agent\Logger\CommandLoggingTrait;
use QL\Hal\Core\Entity\Build;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Build an application for a particular environment.
 *
 * The amount of dependencies of this command is too damn high.
 */
class BuildCommand extends Command
{
    use CommandTrait;
    use CommandLoggingTrait;
    use FormatterTrait;

    /**
     * A list of all possible exit codes of this command
     *
     * @var array
     */
    private static $codes = [
        0 => 'Success!',
        1 => 'Build details could not be resolved.',
        2 => 'Repository archive could not be downloaded.',
        4 => 'Repository archive could not be unpacked.',
        8 => 'Build command failed.',
        16 => 'Build archive could not be created.'
    ];

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var Resolver
     */
    private $resolver;

    /**
     * @var Downloader
     */
    private $downloader;

    /**
     * @var Unpacker
     */
    private $unpacker;

    /**
     * @var Builder
     */
    private $builder;

    /**
     * @var Packer
     */
    private $packer;

    /**
     * @var DownloadProgressHelper
     */
    private $progress;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var string[]
     */
    private $artifacts;

    /**
     * @var Build|null
     */
    private $build;

    /**
     * @param string $name
     * @param EntityManager $entityManager
     * @param Clock $clock
     * @param Resolver $resolver
     * @param Downloader $downloader
     * @param Builder $builder
     * @param Packer $packer
     * @param DownloadProgressHelper $progress
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(
        $name,
        EntityManager $entityManager,
        Clock $clock,
        Resolver $resolver,
        Downloader $downloader,
        Unpacker $unpacker,
        Builder $builder,
        Packer $packer,
        DownloadProgressHelper $progress,
        ProcessBuilder $processBuilder
    ) {
        parent::__construct($name);

        $this->entityManager = $entityManager;
        $this->clock = $clock;

        $this->resolver = $resolver;
        $this->downloader = $downloader;
        $this->unpacker = $unpacker;
        $this->builder = $builder;
        $this->packer = $packer;

        $this->progress = $progress;
        $this->processBuilder = $processBuilder;

        $this->artifacts = [];
    }

    /**
     * In case of error or critical failure, ensure that we clean up the build artifacts.
     *
     * Note that this is only called for exceptions and non-fatal errors.
     * Fatal errors WILL NOT trigger this.
     *
     * @return null
     */
    public function __destruct()
    {
        $this->blowTheHatch();
    }

    /**
     *  Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Build an application build.')
            ->addArgument(
                'BUILD_ID',
                InputArgument::REQUIRED,
                'The Build ID to build.'
            );

        $help = ['<fg=cyan>Exit codes:</fg=cyan>'];
        foreach (static::$codes as $code => $message) {
            $help[] = $this->formatSection($code, $message);
        }
        $this->setHelp(implode("\n", $help));
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
        // expected build statuses
        // Waiting, Downloading, Building, Finished, Error

        $buildId = $input->getArgument('BUILD_ID');

        if (!$properties = $this->resolve($output, $buildId)) {
            return $this->failure($output, 1);
        }

        $this->prepare($output, $properties);

        if (!$this->download($output, $properties)) {
            return $this->failure($output, 2);
        }

        if (!$this->unpack($output, $properties)) {
            return $this->failure($output, 4);
        }

        if (!$this->build($output, $properties)) {
            return $this->failure($output, 8);
        }

        if (!$this->pack($output, $properties)) {
            return $this->failure($output, 16);
        }

        $this->success($output);
    }

    /**
     * @return null
     */
    private function cleanup()
    {
        $this->processBuilder->setPrefix(['rm', '-rf']);

        $poppers = 0;
        while ($this->artifacts && $poppers < 10) {
            # while loops make me paranoid, ok?
            $poppers++;

            $path = array_pop($this->artifacts);
            $process = $this->processBuilder
                ->setWorkingDirectory(null)
                ->setArguments([$path])
                ->getProcess();

            $process->run();
        }
    }

    /**
     * @param OutputInterface $output
     * @param int $exitCode
     * @return null
     */
    private function finish(OutputInterface $output, $exitCode)
    {
        if ($this->build) {
            $status = ($exitCode === 0) ? 'Success' : 'Error';
            $this->build->setStatus($status);

            $this->build->setEnd($this->clock->read());
            $this->entityManager->merge($this->build);
            $this->entityManager->flush();

            // Only send logs if the build was found
            $type = ($exitCode === 0) ? 'success' : 'failure';

            $this->logAndFlush($type, [
                'build' => $this->build,
                'buildId' => $this->build->getId(),
                'buildExitCode' => $exitCode
            ]);
        }

        $this->cleanup();

        return $exitCode;
    }

    /**
     * @param string $status
     * @param boolean $start
     * @return null
     */
    private function setEntityStatus($status, $start = false)
    {
        if (!$this->build) {
            return;
        }

        $this->build->setStatus($status);
        if ($start) {
            $this->build->setStart($this->clock->read());
        }

        $this->entityManager->merge($this->build);
        $this->entityManager->flush();
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return null
     */
    private function status(OutputInterface $output, $message)
    {
        $this->log('notice', $message);

        $message = sprintf('<comment>%s</comment>', $message);
        $output->writeln($message);
    }

    /**
     * @param OutputInterface $output
     * @param string $buildId
     * @return array|null
     */
    private function resolve(OutputInterface $output, $buildId)
    {
        $this->status($output, 'Resolving build properties');
        return call_user_func($this->resolver, $buildId);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return null
     */
    private function prepare(OutputInterface $output, array $properties)
    {
        $this->build = $properties['build'];

        // Set emergency handler in case of super fatal
        $this->inCaseOfEmergency([$this, 'blowTheHatch']);

        // Update the build status asap so no other worker can pick it up
        $this->setEntityStatus('Building', true);

        // add artifacts for cleanup
        $this->artifacts = $properties['artifacts'];
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function download(OutputInterface $output, array $properties)
    {
        $this->progress->enableDownloadProgress($output);

        $this->status($output, 'Downloading github repository');
        return call_user_func_array($this->downloader, [
            $properties['githubUser'],
            $properties['githubRepo'],
            $properties['githubReference'],
            $properties['buildFile']
        ]);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function unpack(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Unpacking github repository');
        return call_user_func(
            $this->unpacker,
            $properties['buildFile'],
            $properties['buildPath']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function build(OutputInterface $output, array $properties)
    {
        if (!$properties['buildCommand']) {
            $this->status($output, 'Skipping build command');
            return true;
        }

        $this->status($output, 'Running build command');
        return call_user_func_array($this->builder, [
            $properties['buildPath'],
            $properties['buildCommand'],
            $properties['environmentVariables']
        ]);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @return boolean
     */
    private function pack(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Packing build into archive');
        return call_user_func(
            $this->packer,
            $properties['buildPath'],
            $properties['archiveFile']
        );
    }

    /**
     * Emergency failsafe
     */
    public function blowTheHatch()
    {
        $this->cleanup();

        // If we got to this point and the status is still "Building", something terrible has happened.
        if ($this->build && $this->build->getStatus() === 'Building') {
            $this->build->setEnd($this->clock->read());
            $this->setEntityStatus('Error');

            $this->logAndFlush('failure', [
                'build' => $this->build,
                'buildId' => $this->build->getId(),
                'buildExitCode' => 9000
            ]);
        }
    }
}
