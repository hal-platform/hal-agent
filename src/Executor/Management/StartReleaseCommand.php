<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Management;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Hal\Agent\Application\HalClient;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use Hal\Agent\Executor\Runner\DeployCommand;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\Target;
use QL\MCP\Common\GUID;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

class StartReleaseCommand implements ExecutorInterface
{
    use ExecutorTrait;

    private const STEPS = [];

    private const COMMAND_TITLE = 'Create a release and run the deployment';
    private const MSG_SUCCESS = 'Release created.';

    private const ERR_NO_TARGET = 'Target not found.';
    private const ERR_API_ERROR = 'An error was returned from the API.';

    private const HELP_BUILD = 'The ID of the build to deploy.';
    private const HELP_TARGET = 'The ID or name of the target to deploy to.';

    /**
     * @var EntityRepository
     */
    private $targetRepo;
    private $buildRepo;

    /**
     * @var DeployCommand
     */
    private $runner;

    /**
     * @var HalClient
     */
    private $hal;

    /**
     * @param EntityManagerInterface $em
     * @param DeployCommand $runner
     * @param HalClient $hal
     */
    public function __construct(EntityManagerInterface $em, DeployCommand $runner, HalClient $hal)
    {
        $this->targetRepo = $em->getRepository(Target::class);
        $this->buildRepo = $em->getRepository(Build::class);

        $this->runner = $runner;
        $this->hal = $hal;
    }

    /**
     * @param Command $command
     *
     * @return void
     */
    public static function configure(Command $command)
    {
        $command
            ->setDescription('Create a release to be deployed to a target by a runner.')
            ->setAliases(['deploy'])

            ->addArgument('BUILD_ID', InputArgument::REQUIRED, self::HELP_BUILD)
            ->addArgument('TARGET', InputArgument::REQUIRED, self::HELP_TARGET);
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $exit = $this->create($io);

        if (is_int($exit)) {
            return $exit;
        }

        $newIO = $this->buildIO([DeployCommand::PARAM_RELEASE => $exit['id'] ?? '']);

        return $this->runner->execute($newIO);
    }

    /**
     * Return the API response or exit code on failure.
     *
     * @param IOInterface $io
     *
     * @return array|int
     */
    public function create(IOInterface $io)
    {
        $buildID = $io->getArgument('BUILD_ID');
        $target = $io->getArgument('TARGET');

        $io->title(self::COMMAND_TITLE);

        if (!$target = $this->findTarget($target, $buildID)) {
            return $this->failure($io, self::ERR_NO_TARGET);
        }

        $releases = $this->hal->createRelease($buildID, $target->id());
        if (!$releases) {
            $io->error($this->hal->apiErrors());
            return $this->failure($io, self::ERR_API_ERROR);
        }

        $release = $releases['_embedded']['releases'][0] ?? [];

        $id = $release['id'] ?? 'Unknown';
        $pageURL = $release['_links']['page']['href'] ?? 'Unknown';

        $targetName = $release['_links']['target']['title'] ?? 'Unknown';
        $applicationName = $release['_links']['application']['title'] ?? 'Unknown';
        $environmentName = $release['_links']['environment']['title'] ?? 'Unknown';

        $build = $release['_embedded']['build'] ?? [];

        $buildID = $build['id'] ?? 'Unknown';
        $buildPageURL = $build['_links']['page']['href'] ?? 'Unknown';
        $vcs = '';
        $reference = $build['reference'] ?? 'Unknown';
        $commitSHA = $build['commit'] ?? 'Unknown';

        // todo - update to embedded VCS
        $vcs = $build['_links']['github_reference_page']['href'] ?? 'Unknown';

        $io->section('Details');
        $io->listing([
            sprintf('Application: <info>%s</info>', $applicationName),
            sprintf('Environment: <info>%s</info>', $environmentName),
        ]);

        $io->section('Build Information');
        $io->listing([
            sprintf('ID: <info>%s</info>', $buildID),
            sprintf('URL: <info>%s</info>', $buildPageURL),
            sprintf('VCS: <info>%s</info>', $vcs),
            sprintf('Reference: <info>%s</info> (%s)', $reference, $commitSHA)
        ]);

        $io->section('Release Information');
        $io->listing([
            sprintf('ID: <info>%s</info>', $id),
            sprintf('URL: <info>%s</info>', $pageURL),
            sprintf('Target: <info>%s</info>', $targetName),
        ]);

        $this->success($io, self::MSG_SUCCESS);
        return $release;
    }

    /**
     * Find target from ID or name.
     *
     * @param string $name
     * @param string $buildID
     *
     * @return Target|null
     */
    private function findTarget($name, $buildID)
    {
        // todo - need a better way to auto fail if not guidy
        $isGUID = GUID::createFromHex($name);
        $isBuildGUID = GUID::createFromHex($buildID);

        if ($isGUID && $target = $this->targetRepo->find($name)) {
            return $target;
        }

        if (!$isBuildGUID) {
            return false;
        }

        // We need to find the build as well, because target names are not unique,
        // so we can limit the scope to just this app.
        if (!$build = $this->buildRepo->find($buildID)) {
            return null;
        }

        if ($targets = $this->targetRepo->findBy(['name' => $name, 'application' => $build->application()])) {
            return (count($targets) === 1) ? $targets[0] : null;
        }

        return null;
    }
}
