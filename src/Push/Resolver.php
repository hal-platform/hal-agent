<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push;

use Doctrine\ORM\EntityManagerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Utility\BuildEnvironmentResolver;
use QL\Hal\Agent\Utility\DefaultConfigHelperTrait;
use QL\Hal\Agent\Utility\EncryptedPropertyResolver;
use QL\Hal\Agent\Utility\ResolverTrait;
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

    /**
     * @var string
     */
    const ZIP_FILE = 'hal9000-eb-%s.zip';

    /**
     * @var string
     */
    const TAR_FILE = 'hal9000-s3-%s.tar.gz';

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

                // elastic beanstalk
                'tempZipArchive' => $this->generateTempZipArchiveFile($push->id()),

                // s3
                'tempTarArchive' => $this->generateTempTarArchiveFile($push->id()),
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
        $deploymentSystemProperties = $this->buildDeploymentSystemProperties($method, $push);
        $properties = array_merge($properties, $deploymentSystemProperties);

        // build system configuration
        $buildSystemProperties = $this->buildEnvironmentResolver->getPushProperties($push);
        $properties = array_merge($properties, $buildSystemProperties);

        // attempt to add push-specific properties to build system props
        $this->addPushVarsToBuildVars($properties);

        // Get encrypted properties for use in build_transform, with sources as well (for logging)
        $encryptedProperties = $this->encryptedResolver->getEncryptedPropertiesWithSources(
            $build->application(),
            $build->environment()
        );
        $properties = array_merge($properties, $encryptedProperties);

        // add artifacts to delete
        $properties['artifacts'] = $this->findPushArtifacts($properties);

        return $properties;
    }

    /**
     * @todo Clean up this terrible method
     *
     * @param array $properties
     *
     * @return void
     */
    private function addPushVarsToBuildVars(array &$properties)
    {
        if (!isset($properties['rsync']['environmentVariables']) && !isset($properties['script']['environmentVariables'])) {
            return;
        }

        // add rsync props
        if (isset($properties['rsync']['environmentVariables'])) {
            $hostname = $properties['rsync']['environmentVariables']['HAL_HOSTNAME'];
            $path = $properties['rsync']['environmentVariables']['HAL_PATH'];

            // Add to unix env
            if (isset($properties['unix']['environmentVariables'])) {
                $properties['unix']['environmentVariables']['HAL_HOSTNAME'] = $hostname;
                $properties['unix']['environmentVariables']['HAL_PATH'] = $path;
            }
        }

        // add script props
        if (isset($properties['script']['environmentVariables'])) {
            $context = $properties['script']['environmentVariables']['HAL_CONTEXT'];

            // Add to unix env
            if (isset($properties['unix']['environmentVariables'])) {
                $properties['unix']['environmentVariables']['HAL_CONTEXT'] = $context;
            }
        }
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

        $properties = [];

        $now = $this->clock->read();
        $replacements = [
            'APPID' => $application->id(),
            'APPNAME' => $application->name(),
            'BUILDID' => $build->id(),
            'PUSHID' => $push->id(),
            'DATE' => $now->format('Ymd', 'UTC'),
            'TIME' => $now->format('His', 'UTC')
        ];

        if ($method === ServerEnum::TYPE_RSYNC) {
            $properties[$method] = $this->buildRsyncProperties($build, $deployment, $server);

        } elseif ($method === ServerEnum::TYPE_SCRIPT) {
            $properties[$method] = $this->buildScriptProperties($build, $deployment);

        } elseif ($method === ServerEnum::TYPE_EB) {

            $template = $deployment->s3file() ?: self::DEFAULT_EB_FILENAME;
            $properties[$method] = [
                'region' => $server->name(),
                'credential' => $deployment->credential() ? $deployment->credential()->aws() : null,

                'application' => $deployment->ebName(),
                'environment' => $deployment->ebEnvironment(),

                'bucket' => $deployment->s3bucket(),
                'file' => $this->buildS3Filename($replacements, $template)
            ];

        } elseif ($method === ServerEnum::TYPE_S3) {

            $template = $deployment->s3file() ?: self::DEFAULT_S3_FILENAME;
            $properties[$method] = [
                'region' => $server->name(),
                'credential' => $deployment->credential() ? $deployment->credential()->aws() : null,

                'bucket' => $deployment->s3bucket(),
                'file' => $this->buildS3Filename($replacements, $template)
            ];

        } elseif ($method === ServerEnum::TYPE_CD) {

            $template = $deployment->s3file() ?: self::DEFAULT_CD_FILENAME;
            $properties[$method] = [
                'region' => $server->name(),
                'credential' => $deployment->credential() ? $deployment->credential()->aws() : null,

                'application' => $deployment->cdName(),
                'group' => $deployment->cdGroup(),
                'configuration' => $deployment->cdConfiguration(),

                'bucket' => $deployment->s3bucket(),
                'file' => $this->buildS3Filename($replacements, $template)
            ];

        }

        return $properties;
    }

    /**
     * @param Build $build
     * @param Deployment $deployment
     * @param Server $server
     *
     * @return array
     */
    private function buildRsyncProperties(Build $build, Deployment $deployment, Server $server)
    {
        // validate remote hostname
        $serverName = $server->name();
        if (!$hostname = $this->validateHostname($serverName)) {
            $this->logger->event('failure', sprintf(self::ERR_HOSTNAME_RESOLUTION, $serverName));

            // Revert hostname back to server name, and allow the push to continue.
            $hostname = $serverName;
        }

        $env = [
            'HAL_HOSTNAME' => $hostname,
            'HAL_PATH' => $deployment->path(),

            'HAL_BUILDID' => $build->id(),
            'HAL_COMMIT' => $build->commit(),
            'HAL_GITREF' => $build->branch(),
            'HAL_ENVIRONMENT' => $build->environment()->name(),
            'HAL_REPO' => $build->application()->key()
        ];

        return [
            'remoteUser' => $this->sshUser,
            'remoteServer' => $hostname,
            'remotePath' => $deployment->path(),
            'syncPath' => sprintf('%s@%s:%s', $this->sshUser, $hostname, $deployment->path()),
            'environmentVariables' => $env
        ];
    }

    /**
     * @param Build $build
     * @param Deployment $deployment
     *
     * @return array
     */
    private function buildScriptProperties(Build $build, Deployment $deployment)
    {
        return [
            'environmentVariables' => [
                'HAL_CONTEXT' => $deployment->scriptContext(),

                'HAL_BUILDID' => $build->id(),
                'HAL_COMMIT' => $build->commit(),
                'HAL_GITREF' => $build->branch(),
                'HAL_ENVIRONMENT' => $build->environment()->name(),
                'HAL_REPO' => $build->application()->key()
            ]
        ];
    }

    /**
     * @param array $replacements
     * @param string $template
     *
     * @return string
     */
    private function buildS3Filename(array $replacements, $template)
    {
        $file = $template;

        foreach ($replacements as $name => $val) {
            $name = '$' . $name;
            $file = str_replace($name, $val, $file);
        }

        return $file;
    }

    /**
     * Find the push artifacts that must be cleaned up after push.
     *
     * @param array $properties
     *
     * @return array
     */
    private function findPushArtifacts(array $properties)
    {
        return [
            $properties['location']['tempArchive'],
            $properties['location']['tempZipArchive'],
            $properties['location']['tempTarArchive'],
            $properties['location']['path']
        ];
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
     * Generate a temporary target for the zip archive (Used for EB Deployments)
     *
     * @param string $id
     *
     * @return string
     */
    private function generateTempZipArchiveFile($id)
    {
        return $this->getLocalTempPath() . sprintf(static::ZIP_FILE, $id);
    }

    /**
     * Generate a temporary target for the zip archive (Used for EB Deployments)
     *
     * @param string $id
     *
     * @return string
     */
    private function generateTempTarArchiveFile($id)
    {
        return $this->getLocalTempPath() . sprintf(static::TAR_FILE, $id);
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
