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
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Server;
use QL\Hal\Core\Repository\PushRepository;
use QL\Hal\Core\Type\EnumType\ServerEnum;
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
    const ARCHIVE_FILE = 'hal9000-aws-%s';

    /**
     * @var string
     */
    const ERR_NOT_FOUND = 'Push "%s" could not be found!';
    const ERR_BAD_STATUS = 'Push "%s" has a status of "%s"! It cannot be redeployed.';
    const ERR_CLOBBERING_TIME = 'Push "%s" is trying to clobber a running push! It cannot be deployed at this time.';
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
     * @var PushRepository
     */
    private $pushRepo;

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
        $this->pushRepo = $em->getRepository(Push::CLASS);
        $this->clock = $clock;
        $this->buildEnvironmentResolver = $buildEnvironmentResolver;
        $this->encryptedResolver = $encryptedResolver;

        $this->sshUser = $sshUser;
        $this->githubBaseUrl = $githubBaseUrl;
    }

    /**
     * @param string $pushId
     *
     * @throws PushException
     *
     * @return array
     */
    public function __invoke($pushId)
    {
        if (!$push = $this->pushRepo->find($pushId)) {
            throw new PushException(sprintf(self::ERR_NOT_FOUND, $pushId));
        }

        if ($push->status() !== 'Waiting') {
            throw new PushException(sprintf(self::ERR_BAD_STATUS, $pushId, $push->status()));
        }

        if ($this->hasConcurrentDeployment($push->deployment())) {
            throw new PushException(sprintf(self::ERR_CLOBBERING_TIME, $pushId));
        }

        $deployment = $push->deployment();
        $build = $push->build();
        $application = $push->application();
        $server = $deployment->server();

        $method = $server->type();

        $properties = [
            'build' => $build,
            'push' => $push,
            'method' => $method,

            // default, overwritten by .hal9000.yml
            'configuration' => $this->buildDefaultConfiguration($application),

            'location' => [
                'path' => $this->generateLocalTempPath($push->id(), 'push'),
                'archive' => $this->generateBuildArchiveFile($build->id()),

                'tempArchive' => $this->generateTempBuildArchiveFile($push->id(), 'push'),

                // codedeploy
                // elastic beanstalk
                // s3
                'tempUploadArchive' => $this->generateTempArchiveFile($push->id()),
            ],

            'pushProperties' => [
                'id' => $build->id(),
                'source' => sprintf(
                    '%s/%s/%s',
                    rtrim($this->githubBaseUrl, '/'),
                    $application->githubOwner(),
                    $application->githubRepo()
                ),
                'env' => $build->environment()->name(),
                'user' => $push->user() ? $push->user()->handle() : null,
                'reference' => $build->branch(),
                'commit' => $build->commit(),
                'date' => $this->clock->read()->format('c', 'America/Detroit')
            ]
        ];

        // deployment system configuration
        $properties[$method] = $this->buildDeploymentSystemProperties($method, $push);

        // build system configuration
        $buildSystemProperties = $this->buildEnvironmentResolver->getPushProperties($push);
        $properties = array_merge($properties, $buildSystemProperties);

        // Merge build and push env for rsync deployment method (used for server commands)
        if ($method === ServerEnum::TYPE_RSYNC) {
            $properties = $this->mergeRsyncBuildAndPushEnvironment($properties);
        }

        // Get encrypted properties for use in build_transform, with sources as well (for logging)
        $encryptedProperties = $this->encryptedResolver->getEncryptedPropertiesWithSources(
            $build->application(),
            $build->environment()
        );
        $properties = array_merge($properties, $encryptedProperties);

        // add artifacts to delete
        $properties['artifacts'] = [
            $properties['location']['tempArchive'],
            $properties['location']['tempUploadArchive'],
            $properties['location']['path']
        ];

        return $properties;
    }

    /**
     * @param string $method
     * @param Push $push
     *
     * @return array
     */
    private function buildDeploymentSystemProperties($method, Push $push)
    {
        $deployment = $push->deployment();
        $build = $push->build();
        $application = $push->application();
        $server = $deployment->server();

        if ($method === ServerEnum::TYPE_RSYNC) {

            $hostname = $this->attemptHostnameValidation($server);

            return [
                'remoteUser' => $this->sshUser,
                'remoteServer' => $hostname,
                'remotePath' => $deployment->path(),
                'syncPath' => sprintf('%s@%s:%s', $this->sshUser, $hostname, $deployment->path()),

                'environmentVariables' => [
                    'HAL_HOSTNAME' => $hostname,
                    'HAL_PATH' => $deployment->path(),
                ]
            ];

        } elseif ($method === ServerEnum::TYPE_SCRIPT) {

            return [];

        } elseif ($method === ServerEnum::TYPE_EB) {

            $replacements = $this->buildTokenReplacements($push);
            $template = $deployment->s3file() ?: self::DEFAULT_EB_FILENAME;

            return [
                'region' => $server->name(),
                'credential' => $deployment->credential() ? $deployment->credential()->aws() : null,

                'application' => $deployment->ebName(),
                'environment' => $deployment->ebEnvironment(),

                'bucket' => $deployment->s3bucket(),
                'file' => $this->buildS3Filename($replacements, $template),
                'src' => $this->buildS3SourcePath($template)
            ];

        } elseif ($method === ServerEnum::TYPE_S3) {

            $replacements = $this->buildTokenReplacements($push);
            $template = $deployment->s3file() ?: self::DEFAULT_S3_FILENAME;

            return [
                'region' => $server->name(),
                'credential' => $deployment->credential() ? $deployment->credential()->aws() : null,

                'bucket' => $deployment->s3bucket(),
                'file' => $this->buildS3Filename($replacements, $template),
                'src' => $this->buildS3SourcePath($template)
            ];

        } elseif ($method === ServerEnum::TYPE_CD) {

            $replacements = $this->buildTokenReplacements($push);
            $template = $deployment->s3file() ?: self::DEFAULT_CD_FILENAME;

            return [
                'region' => $server->name(),
                'credential' => $deployment->credential() ? $deployment->credential()->aws() : null,

                'application' => $deployment->cdName(),
                'group' => $deployment->cdGroup(),
                'configuration' => $deployment->cdConfiguration(),

                'bucket' => $deployment->s3bucket(),
                'file' => $this->buildS3Filename($replacements, $template),
                'src' => $this->buildS3SourcePath($template)
            ];

        }

        return $properties;
    }

    /**
     * @param Server $server
     *
     * @return string
     */
    private function attemptHostnameValidation(Server $server)
    {
        // validate remote hostname
        $serverName = $server->name();
        if (!$hostname = $this->validateHostname($serverName)) {
            $this->logger->event('failure', sprintf(self::ERR_HOSTNAME_RESOLUTION, $serverName));

            // Revert hostname back to server name, and allow the push to continue.
            $hostname = $serverName;
        }

        return $hostname;
    }

    /**
     * @param Push $push
     *
     * @return array
     */
    private function buildTokenReplacements(Push $push)
    {
        $application = $push->application();
        $build = $push->build();

        $now = $this->clock->read();
        return [
            'APPID' => $application->id(),
            'APP' => $application->key(),
            'BUILDID' => $build->id(),
            'PUSHID' => $push->id(),
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
        $system = UnixBuildHandler::SERVER_TYPE;
        $method = ServerEnum::TYPE_RSYNC;

        if (!isset($properties[$method]['environmentVariables']) || !isset($properties[$system]['environmentVariables'])) {
            return $properties;
        }

        $env = array_merge($properties[$method]['environmentVariables'], $properties[$system]['environmentVariables']);

        $properties[$method]['environmentVariables'] = $env;
        $properties[$system]['environmentVariables'] = $env;
    }

    /**
     * @param array $replacements
     * @param string $template
     *
     * @return string
     */
    private function buildS3Filename(array $replacements, $template)
    {
        $delimited = explode(self::SRC_DEST_DELIMITER, $template);
        $file = array_pop($delimited);

        $file = $template;

        foreach ($replacements as $name => $val) {
            $name = '$' . $name;
            $file = str_replace($name, $val, $file);
        }

        return $file;
    }

    /**
     * @param string $template
     *
     * @return string
     */
    private function buildS3SourcePath($template)
    {
        $delimited = explode(self::SRC_DEST_DELIMITER, $template);
        $parts = count($delimited);
        if ($parts === 2) {
            return array_shift($delimited);
        } else {
            // Should be an error, but oh well.
            return self::DEFAULT_AWS_SRC;
        }
    }

    /**
     * @return void
     */
    private function ensureTempExistsAndIsWritable()
    {
        $temp = $this->getLocalTempPath();

        if (!file_exists($temp)) {
            if (!mkdir($temp, 0755, true)) {
                throw new PushException(sprintf(self::ERR_TEMP, $temp));
            }
        }

        if (!is_writeable($temp)) {
            throw new PushException(sprintf(self::ERR_TEMP, $temp));
        }
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
     * This is rather expensive, but we need to prevent concurrent syncs.
     *
     * The push worker also has logic to avoid concurrent syncs, so this is more of a backup. This doesn't seem
     * to ever hit successfully because the child workers fork so quickly.
     *
     * @param Deployment $deployment
     *
     * @return boolean
     */
    private function hasConcurrentDeployment(Deployment $deployment)
    {
        $concurrentSyncs = $this->pushRepo->findBy([
            'status' => 'Pushing',
            'deployment' => $deployment
        ]);

        return (count($concurrentSyncs) > 0);
    }
}
