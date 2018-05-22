<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Hal\Agent\JobConfiguration\DefaultConfigurationTrait;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Type\JobStatusEnum;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class Resolver
{
    use DefaultConfigurationTrait;

    private const ERR_NOT_FOUND = 'Build "%s" could not be found!';
    private const ERR_NOT_PENDING = 'Build "%s" has a status of "%s"! It cannot be rebuilt.';
    private const ERR_TEMP = 'Temporary build space "%s" could not be prepared. Either it does not exist, or is not writeable.';

    /**
     * @var ObjectRepository
     */
    private $buildRepo;

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
        $this->buildRepo = $em->getRepository(Build::class);
        $this->encryptedResolver = $encryptedResolver;
        $this->filesystem = $filesystem;

        $this->localWorkspace = rtrim($tempDir, '/');
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

        $app = $build->application();
        $env = $build->environment();

        // Get encrypted properties for use in build, with sources as well (for logging)
        [
            'encrypted' => $encrypted,
            'sources' => $sources
        ] = $this->encryptedResolver->getEncryptedPropertiesWithSources($app, $env);

        $properties = [
            'job' => $build,

            // default, overwritten by .hal.yaml
            'default_configuration' => $this->buildDefaultConfiguration(),

            'workspace_path' => $this->getLocalWorkspace($build->id(), 'build'),

            'encrypted' => $encrypted,
            'encrypted_sources' => $sources
        ];

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

        if ($build->status() !== JobStatusEnum::TYPE_PENDING) {
            throw new BuildException(sprintf(self::ERR_NOT_PENDING, $buildID, $build->status()));
        }

        return $build;
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
     * @throws BuildException
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
            throw new BuildException(sprintf(self::ERR_TEMP, $temp));
        }
    }
}
