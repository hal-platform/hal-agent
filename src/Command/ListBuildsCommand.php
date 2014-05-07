<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Repository\BuildRepository;
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

    /**
     * A list of all possible exit codes of this command
     *
     * @var array
     */
    private static $codes = [
        0 => 'Success!',
        1 => 'No builds found.'
    ];

    /**
     * @var BuildRepository
     */
    private $buildRepo;

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
     * @param Filesystem $filesystem
     * @param string $archivePath
     */
    public function __construct($name, BuildRepository $buildRepo, Filesystem $filesystem, $archivePath)
    {
        parent::__construct($name);

        $this->buildRepo = $buildRepo;
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
            ->addOption(
                'status',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by status.',
                'Success'
            )
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

        $help = 'Note: pagination is not currently supported.';
        $errors = ['', 'Exit Codes:'];
        foreach (static::$codes as $code => $message) {
            $errors[] = $this->formatSection($code, $message);
        }

        $this->setHelp($help . implode("\n", $errors));
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
        $status = $input->getOption('status');
        $porcelain = $input->getOption('porcelain');
        $verify = $input->getOption('verify');

        // pagination
        $offset = 0;
        $pageSize = null;

        $criteria = [];
        if ($status) {
            $criteria['status'] = $status;
        }

        $builds = $this->buildRepo->findBy($criteria, ['status' => 'ASC'], $pageSize, $offset);
        if (!$builds) {
            return $this->failure($output, 1);
        }

        $table = $this->getHelperSet()->get('table');
        $table->setHeaders(['Status', 'Start Time', 'Id', 'Repository', 'Environment', 'Archive']);

        foreach ($builds as $build) {

            // Dump the id and continue on
            if ($porcelain) {
                $output->writeln($build->getId());
                continue;
            }

            // Waiting builds do not have start times
            $start = ($build->getStart()) ? $build->getStart()->format('c', self::TIMEZONE) : null;
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
                $start,
                $build->getId(),
                $build->getRepository()->getKey(),
                $build->getEnvironment()->getKey(),
                $archive
            ]);
        }

        $output->writeln(sprintf('Displaying %s - %s out of %s: ', $offset + 1, $offset + count($builds), count($builds)));
        $table->render($output);

        $this->success($output, '');
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
