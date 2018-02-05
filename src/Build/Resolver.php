<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Hal\Agent\Utility\BuildEnvironmentResolver;
use Hal\Agent\Utility\DefaultConfigHelperTrait;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Agent\Utility\ResolverTrait;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;

/**
 * Resolve build properties from user and environment input
 */
class Resolver
{
    use DefaultConfigHelperTrait;
    use ResolverTrait;

    /**
     * @var string
     */
    const DOWNLOAD_FILE = 'hal-download-%s.tar.gz';
    const TRANSFER_FILE = 'hal-aws-%s-%s.tar.gz';

    /**
     * @var string
     */
    const ERR_NOT_FOUND = 'Build "%s" could not be found!';
    const ERR_NOT_WAITING = 'Build "%s" has a status of "%s"! It cannot be rebuilt.';
    const ERR_TEMP = 'Temporary build space "%s" could not be prepared. Either it does not exist, or is not writeable.';

    /**
     * @var EntityRepository
     */
    private $buildRepo;

    /**
     * @var BuildEnvironmentResolver
     */
    private $environmentResolver;

    /**
     * @var EncryptedPropertyResolver
     */
    private $encryptedResolver;

    /**
     * @param EntityManagerInterface $em
     * @param BuildEnvironmentResolver $environmentResolver
     * @param EncryptedPropertyResolver $encryptedResolver
     */
    public function __construct(
        EntityManagerInterface $em,
        BuildEnvironmentResolver $environmentResolver,
        EncryptedPropertyResolver $encryptedResolver
    ) {
        $this->buildRepo = $em->getRepository(Build::class);
        $this->environmentResolver = $environmentResolver;
        $this->encryptedResolver = $encryptedResolver;
    }

    /**
     * @param string $buildID
     *
     * @throws BuildException
     *
     * @return array
     */
    public function __invoke(string $buildID)
    {
        $build = $this->getBuild($buildID);

        $properties = [
            'build' => $build,

            // default, overwritten by .hal9000.yml
            'default_configuration' => $this->buildDefaultConfiguration(),

            'workspace_path' => $this->generateLocalTempPath($build->id(), 'build'),

            // 'location' => [
            //     'download' => $this->generateRepositoryDownloadFile($build->id()),
            //     'path' => $this->generateLocalTempPath($build->id(), 'build'),
            //     'archive' => $this->generateBuildArchiveFile($build->id()),
            //     'tempArchive' => $this->generateTempBuildArchiveFile($build->id(), 'build')

            //     'windowsInputArchive' => $this->generateTempTransferFile($build->id(), 'windows-input'),
            //     'windowsOutputArchive' => $this->generateTempTransferFile($build->id(), 'windows-output')

            // ]
        ];

        // Get encrypted properties for use in build, with sources as well (for logging)
        $encryptedProperties = $this->encryptedResolver->getEncryptedPropertiesWithSources($build->application(), $build->environment());
        $properties = array_merge($properties, $encryptedProperties);

        $properties['artifacts'] = [
            // $properties['location']['download'],
            $properties['workspace_path'],
            // $properties['location']['tempArchive']
        ];

        // build system configuration
        // $buildSystemProperties = $this->environmentResolver->getBuildProperties($build);
        // $properties = array_merge($properties, $buildSystemProperties);

        $this->ensureTempExistsAndIsWritable();

        return $properties;
    }

    /**
     * @param string $buildID
     *
     * @return Build
     */
    private function getBuild($buildID)
    {
        $build = $this->buildRepo->find($buildID);
        if (!$build instanceof Build) {
            throw new BuildException(sprintf(self::ERR_NOT_FOUND, $buildID));
        }

        if ($build->status() !== 'pending') {
            throw new BuildException(sprintf(self::ERR_NOT_WAITING, $buildID, $build->status()));
        }

        return $build;
    }

    /**
     * @return void
     * @throws BuildException
     */
    private function ensureTempExistsAndIsWritable()
    {
        $temp = $this->getLocalTempPath();

        if (!file_exists($temp)) {
            if (!mkdir($temp, 0755, true)) {
                throw new BuildException(sprintf(self::ERR_TEMP, $temp));
            }
        }

        if (!is_writeable($temp)) {
            throw new BuildException(sprintf(self::ERR_TEMP, $temp));
        }
    }

    /**
     * Generate a target for the build archive.
     *
     * @param string $id
     *
     * @return string
     */
    private function generateRepositoryDownloadFile($id)
    {
        return $this->getLocalTempPath() . sprintf(static::DOWNLOAD_FILE, $id);
    }

    /**
     * @param string $id
     * @param string $type
     *
     * @return string
     */
    private function generateTempTransferFile($id, $type)
    {
        return $this->getLocalTempPath() . sprintf(static::TRANSFER_FILE, $id, $type);
    }
}
