<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use MCP\DataType\Time\TimePoint;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Tools\Pagination\Paginator;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Repository\BuildRepository;
use QL\Hal\Core\Entity\Repository\EnvironmentRepository;
use Symfony\Component\Console\Command\Command;
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

    /**
     * @var string
     */
    const TIMEZONE = 'America/Detroit';
    const FS_ARCHIVE_PREFIX = 'hal9000-%s.tar.gz';
    const PAGE_SIZE = 500;

    /**
     * A list of all possible exit codes of this command
     *
     * @var array
     */
    private static $codes = [
        0 => 'Success!',
        1 => 'No builds found.',
        2 => 'Invalid environment specified.',
        3 => 'Invalid date specified. Please use "YYYY-MM-DD" format.'
    ];

    /**
     * @var BuildRepository
     */
    private $buildRepo;

    /**
     * @var EnvironmentRepository
     */
    private $envRepo;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var string
     */
    private $archivePath;

    /**
     * @param string $name
     * @param BuildRepository $buildRepo
     * @param EnvironmentRepository $envRepo
     * @param Filesystem $filesystem
     * @param string $archivePath
     */
    public function __construct($name,
        BuildRepository $buildRepo,
        EnvironmentRepository $envRepo,
        Filesystem $filesystem,
        $archivePath
    ) {
        parent::__construct($name);

        $this->buildRepo = $buildRepo;
        $this->envRepo = $envRepo;
        $this->filesystem = $filesystem;
        $this->archivePath = $archivePath;
    }

    /**
     *  Configure the command
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
            '<fg=yellow>Results can be filtered by status, environment, or age',
            'Status examples (<fg=green>Waiting</fg=green>, <fg=green>Building</fg=green>, <fg=green>Success</fg=green>, <fg=green>Error</fg=green>)',
            'Environment examples (<fg=green>test</fg=green>, <fg=green>beta</fg=green>, <fg=green>prod</fg=green>)',
            'Age examples (<fg=green>2014-8-15</fg=green>, <fg=green>2014-05-08</fg=green>)</fg=yellow>',
            'Note: Age filtering uses an exclusive range. If 2014-8-15 is specified, builds from 2014-8-14 and before will be displayed.',
            '',
            '<fg=cyan>Exit codes:</fg=cyan>'
        ];
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
        // filters
        $status = $input->getOption('status');
        $environment = $input->getOption('environment');
        $older = $input->getOption('older-than');

        // flags
        $porcelain = $input->getOption('porcelain');
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

        if ($environment) {
            if (!$env = $this->envRepo->findOneBy(['key' => $environment])) {
                return $this->failure($output, 2);
            }

            $criteria->andWhere(Criteria::expr()->eq('environment', $env));
        }

        if ($older) {
            $date = explode('-', $older);
            if (count($date) !== 3) {
                return $this->failure($output, 3);
            }

            list($y, $m, $d) = $date;
            $criteria->andWhere(Criteria::expr()->lt('created', new TimePoint($y, $m, $d, 0, 0, 0, self::TIMEZONE)));
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
            $builder->addCriteria($criteria);

            $paginator = new Paginator($builder);
            $totalCount = count($paginator);
        }

        // porcelain
        if ($porcelain) {
            foreach ($builds as $build) {
                $output->writeln($build->getId());
            }

            return 0;
        }

        // full table
        $table = $this->getHelperSet()->get('table');
        $table->setHeaders(['Status', 'Created Time', 'Id', 'Repository', 'Environment', 'Archive']);

        foreach ($builds as $build) {
            $archive = $this->generateArchiveLocation($build);

            // Check for archive existence
            if ($archive && $verify && !$this->filesystem->exists($archive)) {
                $file = explode(DIRECTORY_SEPARATOR, $archive);
                $archive = sprintf(
                    '<error>%s not found!</error>',
                    end($file)
                );
            }

            $table->addRow([
                $build->getStatus(),
                $build->getCreated() ? $build->getCreated()->format('c', self::TIMEZONE) : null,
                $build->getId(),
                $build->getRepository()->getKey(),
                $build->getEnvironment()->getKey(),
                $archive
            ]);
        }

        $start = $page * self::PAGE_SIZE;

        $output->writeln(sprintf('Displaying %s - %s out of %s: ', $start + 1, $start + $buildCount, $totalCount));
        $table->render($output);

        return 0;
    }

    /**
     *  @param Build $build
     *  @return string
     */
    private function generateArchiveLocation(Build $build)
    {
        if ($build->getStatus() !== 'Success') {
            return '';
        }

        return sprintf(
            '%s%s%s',
            rtrim($this->archivePath, '/'),
            DIRECTORY_SEPARATOR,
            sprintf(self::FS_ARCHIVE_PREFIX, $build->getId())
        );
    }
}
