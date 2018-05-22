<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy;

use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Hal\Agent\JobConfiguration\DefaultConfigurationTrait;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\Entity\JobType\Release;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class Resolver
{
    use DefaultConfigurationTrait;

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
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var string
     */
    private $localWorkspace;

    /**
     * @param EntityManagerInterface $em
     * @param EncryptedPropertyResolver $encryptedResolver
     * @param Filesystem $filesystem
     * @param string $tempDir
     */
    public function __construct(
        EntityManagerInterface $em,
        EncryptedPropertyResolver $encryptedResolver,
        Filesystem $filesystem,
        string $tempDir
    ) {
        $this->releaseRepo = $em->getRepository(Release::class);
        $this->encryptedResolver = $encryptedResolver;
        $this->filesystem = $filesystem;

        $this->localWorkspace = rtrim($tempDir, '/');
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

        // Get encrypted properties for use in deploy, with sources as well (for logging)
        [
            'encrypted' => $encrypted,
            'sources' => $sources
        ] = $this->encryptedResolver->getEncryptedPropertiesWithSources($application, $environment);

        $properties = [
            'job' => $release,
            'platform' => $platform,

            // default, overwritten by .hal.yaml
            'default_configuration' => $this->buildDefaultConfiguration(),

            'workspace_path' => $this->getLocalWorkspace($release->id(), 'release'),

            'encrypted' => $encrypted,
            'encrypted_sources' => $sources
        ];

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

        $lastJob = $release->target()->lastJob();
        if ($lastJob && $release !== $lastJob && $lastJob->inProgress()) {
            throw new DeployException(sprintf(self::ERR_CLOBBERING_TIME, $releaseID));
        }

        return $release;
    }

    /**
     * Generate a unique temporary scratch space path for performing file system actions.
     *
     * Example:
     * /tmp/builds/hal-build-1234
     *
     * @param string $id
     * @param string $type
     *
     * @return string
     */
    private function getLocalWorkspace($id, $type)
    {
        $temp = $this->localWorkspace;
        $type = ($type === 'release') ? 'release' : 'build';

        return "${temp}/hal-${type}-${id}";
    }

    /**
     * @throws DeployException
     *
     * @return void
     */
    private function ensureTempExistsAndIsWritable()
    {
        $temp = $this->localWorkspace;

        try {
            if (!$this->filesystem->exists($temp)) {
                $this->filesystem->mkdir($temp, 0755);
            }

            $this->filesystem->touch("${temp}/.hal-agent");

        } catch (IOException $e) {
            throw new DeployException(sprintf(self::ERR_TEMP, $temp));
        }
    }
}
