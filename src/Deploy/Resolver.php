<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy;

use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Hal\Agent\Utility\DefaultConfigHelperTrait;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Agent\Utility\ResolverTrait;
use Hal\Core\Entity\JobType\Release;

class Resolver
{
    use DefaultConfigHelperTrait;
    use ResolverTrait;

    private const ERR_NOT_FOUND = 'Release "%s" could not be found!';
    private const ERR_NOT_PENDING = 'Release "%s" has a status of "%s"! It cannot be redeployed.';
    private const ERR_CLOBBERING_TIME = 'Release "%s" is trying to clobber a running release! It cannot be deployed at this time.';
    private const ERR_TEMP = 'Temporary build space "%s" could not be prepared. Either it does not exist, or is not writeable.';

    /**
     * @var ObjectRepository
     */
    private $releaseRepo;

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
        $this->releaseRepo = $em->getRepository(Release::class);
        $this->encryptedResolver = $encryptedResolver;
    }

    /**
     * @param string $releaseID
     *
     * @throws DeployException
     *
     * @return array
     */
    public function __invoke(string $releaseID): array
    {
        $release = $this->getRelease($releaseID);

        $application = $release->application();
        $build = $release->build();

        $platform = $release->target()->type();
        $environment = $release->target()->environment();

        $properties = [
            'job' => $release,
            'platform' => $platform,

            // default, overwritten by .hal.yaml
            'default_configuration' => $this->buildDefaultConfiguration(),

            'workspace_path' => $this->generateLocalTempPath($release->id(), 'release'),
            'artifact_stored_file' => $this->generateBuildArchiveFile($build->id()),
        ];

        // Get encrypted properties for use in build, with sources as well (for logging)
        $encryptedProperties = $this->encryptedResolver->getEncryptedPropertiesWithSources($application, $environment);
        $properties = array_merge($properties, $encryptedProperties);

        $this->ensureTempExistsAndIsWritable();

        return $properties;
    }

    /**
     * @param string $releaseID
     *
     * @throws DeployException
     *
     * @return Release
     */
    private function getRelease($releaseID)
    {
        $release = $this->releaseRepo->find($releaseID);
        if (!$release instanceof Release) {
            throw new DeployException(sprintf(self::ERR_NOT_FOUND, $releaseID));
        }

        if ($release->status() !== 'pending') {
            throw new DeployException(sprintf(self::ERR_NOT_PENDING, $releaseID, $release->status()));
        }

        // $lastJob = $release->target()->lastJob();
        // if ($lastJob && $lastJob->inProgress()) {
            // throw new DeployException(sprintf(self::ERR_CLOBBERING_TIME, $releaseID));
        // }

        return $release;
    }

    /**
     * @throws DeployException
     *
     * @return void
     */
    private function ensureTempExistsAndIsWritable()
    {
        $temp = $this->getLocalTempPath();

        if (!file_exists($temp)) {
            if (!mkdir($temp, 0755, true)) {
                throw new DeployException(sprintf(self::ERR_TEMP, $temp));
            }
        }

        if (!is_writeable($temp)) {
            throw new DeployException(sprintf(self::ERR_TEMP, $temp));
        }
    }
}
