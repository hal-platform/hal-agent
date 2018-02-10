<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Hal\Agent\Utility\DefaultConfigHelperTrait;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Agent\Utility\ResolverTrait;
use Hal\Core\Entity\JobType\Build;

class Resolver
{
    use DefaultConfigHelperTrait;
    use ResolverTrait;

    private const ERR_NOT_FOUND = 'Build "%s" could not be found!';
    private const ERR_NOT_WAITING = 'Build "%s" has a status of "%s"! It cannot be rebuilt.';
    private const ERR_TEMP = 'Temporary build space "%s" could not be prepared. Either it does not exist, or is not writeable.';

    /**
     * @var EntityRepository
     */
    private $buildRepo;

    /**
     * @var EncryptedPropertyResolver
     */
    private $encryptedResolver;

    /**
     * @param EntityManagerInterface $em
     * @param EncryptedPropertyResolver $encryptedResolver
     */
    public function __construct(EntityManagerInterface $em, EncryptedPropertyResolver $encryptedResolver)
    {
        $this->buildRepo = $em->getRepository(Build::class);
        $this->encryptedResolver = $encryptedResolver;
    }

    /**
     * @param string $buildID
     *
     * @throws BuildException
     *
     * @return array
     */
    public function __invoke(string $buildID): array
    {
        $build = $this->getBuild($buildID);

        $properties = [
            'build' => $build,

            // default, overwritten by .hal.yaml
            'default_configuration' => $this->buildDefaultConfiguration(),

            'workspace_path' => $this->generateLocalTempPath($build->id(), 'build'),
            'artifact_stored_file' => $this->generateBuildArchiveFile($build->id()),
        ];

        // Get encrypted properties for use in build, with sources as well (for logging)
        $encryptedProperties = $this->encryptedResolver->getEncryptedPropertiesWithSources($build->application(), $build->environment());
        $properties = array_merge($properties, $encryptedProperties);

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
     * @throws BuildException
     *
     * @return void
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
}
