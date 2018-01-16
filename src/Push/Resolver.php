<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push;

use Doctrine\ORM\EntityManagerInterface;
use Hal\Agent\Build\Unix\UnixBuildHandler;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Utility\BuildEnvironmentResolver;
use Hal\Agent\Utility\DefaultConfigHelperTrait;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Agent\Utility\ResolverTrait;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Credential\AWSRoleCredential;
use Hal\Core\Entity\Credential\AWSStaticCredential;
// use Hal\Core\Entity\Group;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Hal\Core\Repository\JobType\ReleaseRepository;
use Hal\Core\Type\CredentialEnum;
use Hal\Core\Type\GroupEnum;
use QL\MCP\Common\Time\Clock;

/**
 * Resolve push properties from user and environment input
 */
class Resolver
{
    use DefaultConfigHelperTrait;
    use HostnameValidatorTrait;
    use ResolverTrait;

    const SRC_DEST_DELIMITER = ':';
    const SYNC_TRANSFER_PREFIX = 'sync=';
    const ARCHIVE_FILE = 'hal9000-aws-%s';
    const TRANSFER_FILE = 'hal9000-aws-%s-%s.tar.gz';

    /**
     * @var string
     */
    const ERR_NOT_FOUND = 'Release "%s" could not be found!';
    const ERR_BAD_STATUS = 'Release "%s" has a status of "%s"! It cannot be redeployed.';
    const ERR_CLOBBERING_TIME = 'Release "%s" is trying to clobber a running release! It cannot be deployed at this time.';
    const ERR_HOSTNAME_RESOLUTION = 'Cannot resolve hostname "%s"';

    const ERR_TEMP = 'Temporary build space "%s" could not be prepared. Either it does not exist, or is not writeable.';

    const DEFAULT_EB_FILENAME = '$APPID/$PUSHID.zip';
    const DEFAULT_S3_FILENAME = '$PUSHID.tar.gz';
    const DEFAULT_CD_FILENAME = '$APPID/$PUSHID.tar.gz';
    const DEFAULT_AWS_SRC = '.';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ReleaseRepository
     */
    private $releaseRepo;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var BuildEnvironmentResolver
     */
    private $buildEnvironmentResolver;

    /**
     * @var EncryptedPropertyResolver
     */
    private $encryptedResolver;

    /**
     * @var string
     */
    private $sshUser;

    /**
     * @var string
     */
    private $githubBaseUrl;

    /**
     * @var string|null
     */
    private $awsKey;
    private $awsSecret;

    /**
     * @param EventLogger $logger
     * @param EntityManagerInterface $em
     * @param Clock $clock
     * @param BuildEnvironmentResolver $buildEnvironmentResolver
     * @param EncryptedPropertyResolver $encryptedResolver
     *
     * @param string $sshUser
     * @param string $githubBaseUrl
     */
    public function __construct(
        EventLogger $logger,
        EntityManagerInterface $em,
        Clock $clock,
        BuildEnvironmentResolver $buildEnvironmentResolver,
        EncryptedPropertyResolver $encryptedResolver,
        $sshUser,
        $githubBaseUrl
    ) {
        $this->logger = $logger;
        $this->releaseRepo = $em->getRepository(Release::class);
        $this->clock = $clock;
        $this->buildEnvironmentResolver = $buildEnvironmentResolver;
        $this->encryptedResolver = $encryptedResolver;

        $this->sshUser = $sshUser;
        $this->githubBaseUrl = $githubBaseUrl;
    }

    /**
     * @param string $releaseID
     *
     * @throws PushException
     *
     * @return array
     */
    public function __invoke($releaseID)
    {
        $release = $this->releaseRepo->find($releaseID);
        if (!$release instanceof Release) {
            throw new PushException(sprintf(self::ERR_NOT_FOUND, $releaseID));
        }

        if ($release->status() !== 'pending') {
            throw new PushException(sprintf(self::ERR_BAD_STATUS, $releaseID, $release->status()));
        }

        if ($this->hasConcurrentRelease($release)) {
            throw new PushException(sprintf(self::ERR_CLOBBERING_TIME, $releaseID));
        }

        $target = $release->target();
        $build = $release->build();
        $application = $release->application();
        $group = $target->group();
        $github = $application->gitHub();

        $method = $group->type();

        $properties = [
            'build' => $build,
            'release' => $release,
            'method' => $method,

            // default, overwritten by .hal9000.yml
            'configuration' => $this->buildDefaultConfiguration(),

            'location' => [
                'path' => $this->generateLocalTempPath($release->id(), 'release'),
                'archive' => $this->generateBuildArchiveFile($build->id()),

                'tempArchive' => $this->generateTempBuildArchiveFile($release->id(), 'release'),

                // codedeploy
                // elastic beanstalk
                // s3
                'tempUploadArchive' => $this->generateTempArchiveFile($release->id()),

                // windows builder
                'windowsInputArchive' => $this->generateTempTransferFile($release->id(), 'windows-input'),
                'windowsOutputArchive' => $this->generateTempTransferFile($release->id(), 'windows-output'),
            ],


            'pushProperties' => [
                'id' => $build->id(),
                'source' => sprintf(
                    '%s/%s/%s',
                    rtrim($this->githubBaseUrl, '/'),
                    $github->owner(),
                    $github->repository()
                ),
                'env' => $release->target()->group()->environment()->name(),
                'user' => $release->user() ? $release->user()->username() : null,
                'reference' => $build->reference(),
                'commit' => $build->commit(),
                'date' => $this->clock->read()->format('c', 'America/Detroit')
            ]
        ];

        // deployment system configuration
        $properties[$method] = $this->buildDeploymentSystemProperties($method, $release);

        // build system configuration
        $buildSystemProperties = $this->buildEnvironmentResolver->getReleaseProperties($release);
        $properties = array_merge($properties, $buildSystemProperties);

        // Merge build and push env for rsync deployment method (used for server commands)
        if ($method === GroupEnum::TYPE_RSYNC) {
            $properties = $this->mergeRsyncBuildAndPushEnvironment($properties);
        }

        // Get encrypted properties for use in build_transform, with sources as well (for logging)
        $encryptedProperties = $this->encryptedResolver->getEncryptedPropertiesWithSources(
            $build->application(),
            $release->target()->group()->environment()
        );
        $properties = array_merge($properties, $encryptedProperties);

        // add artifacts to delete
        $properties['artifacts'] = [
            $properties['location']['tempArchive'],
            $properties['location']['tempUploadArchive'],
            $properties['location']['path'],
            $properties['location']['windowsInputArchive'],
            $properties['location']['windowsOutputArchive']
        ];

        return $properties;
    }

    /**
     * @param string $method
     * @param Release $release
     *
     * @return array
     */
    private function buildDeploymentSystemProperties($method, Release $release)
    {
        $target = $release->target();
        $group = $target->group();

        if ($method === GroupEnum::TYPE_RSYNC) {
            //$credential = ($target->credential()->details() instanceof PrivateKeyCredential) ? $target->credential()->details(): null;

            $hostname = $this->attemptHostnameValidation($group);

            return [
                'remoteUser' => $this->sshUser,
                'remoteServer' => $hostname,
                'remotePath' => $target->parameter(Target::PARAM_REMOTE_PATH),
                'syncPath' => sprintf('%s@%s:%s', $this->sshUser, $hostname, $target->parameter(Target::PARAM_REMOTE_PATH)),

                'environmentVariables' => [
                    'HAL_HOSTNAME' => $hostname,
                    'HAL_PATH' => $target->parameter(Target::PARAM_REMOTE_PATH),
                ]

                //'credential' => $credential
            ];

        } elseif ($method === GroupEnum::TYPE_SCRIPT) {
            return [];

        } elseif ($method === GroupEnum::TYPE_EB) {
            $replacements = $this->buildTokenReplacements($release);
            $template = $target->parameter(Target::PARAM_REMOTE_PATH) ?: self::DEFAULT_EB_FILENAME;

            return [
                'region' => $group->name(),
                'credential' => $target->credential() ? $this->getAWSCredentials($target->credential()) : null,

                'application' => $target->parameter(Target::PARAM_APP),
                'environment' => $target->parameter(Target::PARAM_ENV),

                'bucket' => $target->parameter(Target::PARAM_BUCKET),
                'file' => $this->buildS3Filename($replacements, $template),
                'src' => $target->parameter(Target::PARAM_LOCAL_PATH) ?: self::DEFAULT_AWS_SRC
            ];

        } elseif ($method === GroupEnum::TYPE_S3) {
            $replacements = $this->buildTokenReplacements($release);
            $template = $target->parameter(Target::PARAM_REMOTE_PATH) ?: self::DEFAULT_S3_FILENAME;

            return [
                'region' => $group->name(),
                'credential' => $target->credential() ? $this->getAWSCredentials($target->credential()) : null,

                'bucket' => $target->parameter(Target::PARAM_BUCKET),
                'strategy' => $target->parameter(Target::PARAM_S3_METHOD),
                'file' => $this->buildS3Filename($replacements, $template),
                'src' => $target->parameter(Target::PARAM_LOCAL_PATH) ?: self::DEFAULT_AWS_SRC
            ];

        } elseif ($method === GroupEnum::TYPE_CD) {
            $replacements = $this->buildTokenReplacements($release);
            $template = $target->parameter(Target::PARAM_REMOTE_PATH) ?: self::DEFAULT_CD_FILENAME;

            return [
                'region' => $group->name(),
                'credential' => $target->credential() ? $this->getAWSCredentials($target->credential()) : null,

                'application' => $target->parameter(TARGET::PARAM_APP),
                'group' => $target->parameter(Target::PARAM_GROUP),
                'configuration' => $target->parameter(Target::PARAM_CONFIG),

                'bucket' => $target->parameter(Target::PARAM_BUCKET),
                'file' => $this->buildS3Filename($replacements, $template),
                'src' => $target->parameter(Target::PARAM_LOCAL_PATH) ?: self::DEFAULT_AWS_SRC
            ];

        }

        return [];
    }

    /**
     * @param Group $group
     *
     * @return string
     */
    private function attemptHostnameValidation(Group $group)
    {
        // validate remote hostname
        $serverName = $group->name();
        if (!$hostname = $this->validateHostname($serverName)) {
            $this->logger->event('failure', sprintf(self::ERR_HOSTNAME_RESOLUTION, $serverName));

            // Revert hostname back to server name, and allow the push to continue.
            $hostname = $serverName;
        }

        return $hostname;
    }

    /**
     * @param Release $release
     *
     * @return array
     */
    private function buildTokenReplacements(Release $release)
    {
        $application = $release->application();
        $build = $release->build();

        $now = $this->clock->read();
        return [
            'APPID' => $application->id(),
            'APP' => $application->identifier(),
            'BUILDID' => $build->id(),
            'PUSHID' => $release->id(),
            'DATE' => $now->format('Ymd', 'UTC'),
            'TIME' => $now->format('His', 'UTC')
        ];
    }

    /**
     * @param array $properties
     *
     * @return array
     */
    private function mergeRsyncBuildAndPushEnvironment(array $properties)
    {
        $platform = UnixBuildHandler::PLATFORM_TYPE;
        $method = GroupEnum::TYPE_RSYNC;

        if (!isset($properties[$method]['environmentVariables']) || !isset($properties[$platform]['environmentVariables'])) {
            return $properties;
        }

        $env = array_merge($properties[$method]['environmentVariables'], $properties[$platform]['environmentVariables']);

        $properties[$method]['environmentVariables'] = $env;
        $properties[$platform]['environmentVariables'] = $env;

        return $properties;
    }

    /**
     * @param array $replacements
     * @param string $template
     *
     * @return string
     */
    private function buildS3Filename(array $replacements, $template)
    {
        foreach ($replacements as $name => $val) {
            $name = '$' . $name;
            $template = str_replace($name, $val, $template);
        }

        return $template;
    }

    /**
     * Generate a temporary target for the archive (Used for AWS deployments)
     *
     * @param string $id
     *
     * @return string
     */
    private function generateTempArchiveFile($id)
    {
        return $this->getLocalTempPath() . sprintf(static::ARCHIVE_FILE, $id);
    }

    /**
     * Generate a temporary target for the build (Used for windows aws builds)
     *
     * @param string $id
     * @param string $type
     *
     * @return string
     */
    private function generateTempTransferFile($id, $type)
    {
        return $this->getLocalTempPath() . sprintf(static::TRANSFER_FILE, $id, $type);
    }

    /**
     * This is rather expensive, but we need to prevent concurrent syncs.
     *
     * The push worker also has logic to avoid concurrent syncs, so this is more of a backup. This doesn't seem
     * to ever hit successfully because the child workers fork so quickly.
     *
     * @param Release $release
     *
     * @return boolean
     */
    private function hasConcurrentRelease(Release $release)
    {
        $concurrentSyncs = $this->releaseRepo->findBy([
            'status' => 'running',
            'target' => $release->target()
        ]);

        return (count($concurrentSyncs) > 0);
    }

    /**
     * Get AWS Credentials out of Credential object
     *
     * @param Credential $credential
     *
     * @return AWSStaticCredential|AWSRoleCredential
     */
    private function getAWSCredentials(Credential $credential)
    {
        if (!in_array($credential->type(), [CredentialEnum::TYPE_AWS_STATIC, CredentialEnum::TYPE_AWS_ROLE])) {
            return null;
        }

        return $credential->details();
    }
}
