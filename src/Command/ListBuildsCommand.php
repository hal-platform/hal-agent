<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Command;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use Hal\Agent\Utility\ResolverTrait;
use QL\MCP\Common\Time\TimePoint;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * List builds
 *
 * BUILT FOR COMMAND LINE ONLY
 */
class ListBuildsCommand extends Command
{
    use CommandTrait;
    use FormatterTrait;
    use ResolverTrait;

    /**
     * @var string
     */
    const TIMEZONE = 'America/Detroit';
    const PAGE_SIZE = 500;

    /**
     * A list of all possible exit codes of this command
     *
     * @var array
     */
    private static $codes = [
        0 => 'Success!',
        1 => 'No builds found.',
        2 => 'Invalid repository ID specified.',
        3 => 'Invalid environment specified.',
        4 => 'Invalid date specified. Please use "YYYY-MM-DD" format.'
    ];

    /**
     * @var EntityRepository
     */
    private $buildRepo;
    private $applicationRepo;
    private $envRepo;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param string $name
     * @param EntityManagerInterface $em
     * @param Filesystem $filesystem
     * @param string $archivePath
     */
    public function __construct(
        $name,
        EntityManagerInterface $em,
        Filesystem $filesystem,
        $archivePath
    ) {
        parent::__construct($name);

        $this->buildRepo = $em->getRepository(Build::CLASS);
        $this->applicationRepo = $em->getRepository(Application::CLASS);
        $this->envRepo = $em->getRepository(Environment::CLASS);

        $this->filesystem = $filesystem;

        $this->setArchivePath($archivePath);
    }

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('List builds in tabular format.')
            // filters
            ->addOption(
                'status',
                null,
                InputOption::VALUE_OPTIONAL,
                'Filter by status.'
            )
            ->addOption(
                'environment',
                null,
                InputOption::VALUE_OPTIONAL,
                'Filter by environment name.'
            )
            ->addOption(
                'application',
                null,
                InputOption::VALUE_OPTIONAL,
                'Filter by application ID.'
            )
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_OPTIONAL,
                'Filter by age.'
            )

            // page
            ->addOption(
                'page',
                null,
                InputOption::VALUE_OPTIONAL,
                'Only 500 results will be displayed at a time.',
                1
            )

            // flags
            ->addOption(
                'porcelain',
                null,
                InputOption::VALUE_NONE,
                'Return only build IDs.'
            )
            ->addOption(
                'spaces',
                null,
                InputOption::VALUE_NONE,
                'Use spaces instead of newlines as porcelain delimiter'
            )
            ->addOption(
                'verify',
                null,
                InputOption::VALUE_NONE,
                'Verify build archive existence.'
            );

        $help = [
            'Pagination:',
            '<fg=yellow>Results are paged in result sets of 500.',
            'To specify a different page, use the "page" option.</fg=yellow>',
            '',
            'Filtering',
            '<fg=yellow>Results can be filtered by status, repository, environment, or age',
            'Status examples (<fg=green>Waiting</fg=green>, <fg=green>Building</fg=green>, <fg=green>Success</fg=green>, <fg=green>Error</fg=green>)',
            'Repository examples (<fg=green>1</fg=green>, <fg=green>8</fg=green>)',
            'Environment examples (<fg=green>test</fg=green>, <fg=green>beta</fg=green>, <fg=green>prod</fg=green>)',
            'Age examples (<fg=green>2014-8-15</fg=green>, <fg=green>2014-05-08</fg=green>)</fg=yellow>',
            'Note: Age filtering uses an exclusive range. If 2014-8-15 is specified, builds from 2014-8-14 and before will be displayed.',
            '',
            'Piping',
            '<fg=yellow>Porcelain display is used to easily pipe the output build IDs to another process.',
            'Example: <fg=green>./hal builds:list --porcelain --spaces | xargs ./hal build:remove</fg=green>',
            'By default IDs are delimited with a newline, the spaces flag must be used to change this to spaces.</fg=yellow>',
            '<fg=cyan>Exit codes:</fg=cyan>'
        ];
        foreach (static::$codes as $code => $message) {
            $help[] = $this->formatSection($code, $message);
        }
        $this->setHelp(implode("\n", $help));
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // filters
        $status = $input->getOption('status');
        $environment = $input->getOption('environment');
        $application = $input->getOption('application');
        $older = $input->getOption('older-than');

        // flags
        $porcelain = $input->getOption('porcelain');
        $porcelainSpaces = $input->getOption('spaces');
        $verify = $input->getOption('verify');

        // pagination
        $page = (int) $input->getOption('page');
        $page = $page - 1;

        // initial criteria
        $criteria = (new Criteria)
            ->orderBy(['created' => 'ASC'])
            ->setFirstResult($page * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE);

        // add filters
        if ($status) {
            $criteria->andWhere(Criteria::expr()->eq('status', $status));
        }

        if ($application) {
            if (!$application = $this->applicationRepo->findOneBy(['id' => $application])) {
                return $this->failure($output, 2);
            }

            $criteria->andWhere(Criteria::expr()->eq('application', $application));
        }

        if ($environment) {
            if (!$env = $this->envRepo->findOneBy(['name' => $environment])) {
                return $this->failure($output, 3);
            }

            $criteria->andWhere(Criteria::expr()->eq('environment', $env));
        }

        // clone criteria for use in paginator
        $pagerCriteria = clone $criteria;

        if ($older) {
            $date = explode('-', $older);
            if (count($date) !== 3) {
                return $this->failure($output, 4);
            }

            list($y, $m, $d) = $date;
            $tp = new TimePoint($y, $m, $d, 0, 0, 0, self::TIMEZONE);
            $tpFormatted = $tp->format('Y-m-d H:i:s', 'UTC');

            $criteria->andWhere(Criteria::expr()->lt('created', $tp));
            $pagerCriteria->andWhere(Criteria::expr()->lt('created', $tpFormatted));
        }

        // run the query
        $builds = $this->buildRepo->matching($criteria);
        $buildCount = count($builds);

        if ($buildCount === 0) {
            return $this->failure($output, 1);
        }

        // if first page and result set is smaller than a page size, use the query count as the total builds
        // otherwise, fetch it in a separate query
        if ($buildCount < self::PAGE_SIZE && $page === 0) {
            $totalCount = $buildCount;
        } else {

            $builder = $this->buildRepo->createQueryBuilder('build');
            $builder->addCriteria($pagerCriteria);

            $paginator = new Paginator($builder);
            $totalCount = count($paginator);
        }

        // porcelain
        if ($porcelain) {
            $c = 0;
            foreach ($builds as $build) {
                if ($porcelainSpaces) {
                    $delimiter = ($c++ > 0) ? ' ' : '';
                    $output->write($delimiter . $build->id());
                } else {
                    $output->writeln($build->id());
                }
            }

            return 0;
        }

        $rows = [];

        foreach ($builds as $build) {
            $data = [
                $build->status(),
                $build->created() ? $build->created()->format('Y-m-d H:i:s', self::TIMEZONE) : null,
                $build->id(),
                $build->application()->key(),
                $build->environment()->name()
            ];

            $sources = $this->generateArchiveLocations($build);

            // Check for archive existence
            if ($verify) {
                if (!$sources) {
                    $data[] = '';
                    $data[] = '';

                } elseif ($found = $this->findSource($sources)) {
                    $size = filesize($found) / 1048576; // megabyte

                    $data[] = $found;
                    $data[] = round($size, 2);

                } else {
                    $file = explode(DIRECTORY_SEPARATOR, array_pop($sources));

                    $data[] = sprintf('<error>%s not found!</error>', array_pop($file));
                    $data[] = '';
                }
            } else {
                $data[] = count($sources) ? array_shift($sources) : '';
            }

            $rows[] = $data;
        }

        $start = $page * self::PAGE_SIZE;
        $output->writeln(sprintf('Displaying %s - %s out of %s: ', $start + 1, $start + $buildCount, $totalCount));

        $headers = ['Status', 'Created Time', 'Id', 'Repository', 'Environment', 'Archive'];
        if ($verify) {
            $headers[] = 'Size (MB)';
        }

        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render($output);

        return 0;
    }

    /**
     * @param Build $build
     *
     * @return string[]
     */
    private function generateArchiveLocations(Build $build)
    {
        if ($build->status() !== 'Success') {
            return [];
        }

        return [
            $this->generateBuildArchiveFile($build->id()),
            $this->generateLegacyBuildArchiveFile($build->id())
        ];
    }

    /**
     * @param string[] $sources
     *
     * @return string|null
     */
    private function findSource(array $sources)
    {
        foreach ($sources as $potentialSource) {
            if ($this->filesystem->exists($potentialSource)) {
                return $potentialSource;
            }
        }

        return null;
    }
}
