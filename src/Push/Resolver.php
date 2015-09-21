<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Doctrine\ORM\EntityManagerInterface;
use MCP\DataType\Time\Clock;
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

/**
 * Resolve push properties from user and environment input
 */
class Resolver
{
    use DefaultConfigHelperTrait;
    use HostnameValidatorTrait;
    use ResolverTrait;

    /**
     * @type string
     */
    const ZIP_FILE = 'hal9000-eb-%s.zip';

    /**
     * @type string
     */
    const TAR_FILE = 'hal9000-s3-%s.tar.gz';

    /**
     * @type string
     */
    const ERR_NOT_FOUND = 'Push "%s" could not be found!';
    const ERR_BAD_STATUS = 'Push "%s" has a status of "%s"! It cannot be redeployed.';
    const ERR_CLOBBERING_TIME = 'Push "%s" is trying to clobber a running push! It cannot be deployed at this time.';
    const ERR_HOSTNAME_RESOLUTION = 'Cannot resolve hostname "%s"';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type PushRepository
     */
    private $pushRepo;

    /**
     * @type Clock
     */
    private $clock;

    /**
     * @type BuildEnvironmentResolver
     */
    private $buildEnvironmentResolver;

    /**
     * @type EncryptedPropertyResolver
     */
    private $encryptedResolver;

    /**
     * @type string
     */
    private $sshUser;

    /**
     * @type string
     */
    private $githubBaseUrl;

    /**
     * @type string|null
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
                'legacy_archive' => $this->generateLegacyBuildArchiveFile($build->id()),

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
     * @param array $properties
     *
     * @return void
     */
    private function addPushVarsToBuildVars(array &$properties)
    {
        if (!isset($properties['rsync']['environmentVariables'])) {
            return;
        }

        $hostname = $properties['rsync']['environmentVariables']['HAL_HOSTNAME'];
        $path = $properties['rsync']['environmentVariables']['HAL_PATH'];

        // Add to unix env
        if (isset($properties['unix']['environmentVariables'])) {
            $properties['unix']['environmentVariables']['HAL_HOSTNAME'] = $hostname;
            $properties['unix']['environmentVariables']['HAL_PATH'] = $path;
        }

        // Add to windows env
        if (isset($properties['windows']['environmentVariables'])) {
            $properties['windows']['environmentVariables']['HAL_HOSTNAME'] = $hostname;
            $properties['windows']['environmentVariables']['HAL_PATH'] = $path;
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

        if ($method === ServerEnum::TYPE_RSYNC) {
            $properties[$method] = $this->buildRsyncProperties($build, $deployment, $server);

        } elseif ($method === ServerEnum::TYPE_EB) {

            $properties[$method] = [
                'region' => $server->name(),
                'credential' => $deployment->credential() ? $deployment->credential()->aws() : null,

                'application' => $deployment->ebName(),
                'environment' => $deployment->ebEnvironment(),

                'bucket' => $deployment->s3bucket(),
                'file' => sprintf('%s/%s', $application->id(), $push->id())
            ];

        } elseif ($method === ServerEnum::TYPE_EC2) {

            $properties[$method] = [
                'region' => $server->name(),
                'credential' => $deployment->credential() ? $deployment->credential()->aws() : null,

                'pool' => $deployment->ec2Pool(),
                'remotePath' => $deployment->path()
            ];

        } elseif ($method === ServerEnum::TYPE_S3) {

            $buildid = $build->id();
            $pushid = $push->id();
            $date = $this->clock->read()->format('YYYY-MM-DD', 'UTC');

            $file = sprintf('%s.tar.gz', $pushid);
            if ($file = $deployment->s3file()) {
                $file = str_replace('$BUILDID', $buildid, $file);
                $file = str_replace('$PUSHID', $pushid, $file);
                $file = str_replace('$DATE', $date, $file);
            }

            $properties[$method] = [
                'region' => $server->name(),
                'credential' => $deployment->credential() ? $deployment->credential()->aws() : null,

                'bucket' => $deployment->s3bucket(),
                'file' => $file
            ];

        } elseif ($method === ServerEnum::TYPE_CD) {

            $properties[$method] = [
                'region' => $server->name(),
                'credential' => $deployment->credential() ? $deployment->credential()->aws() : null,

                'application' => $deployment->cdName(),
                'group' => $deployment->cdGroup(),
                'configuration' => $deployment->cdConfiguration(),

                'bucket' => $deployment->s3bucket(),
                'file' => sprintf('%s/%s.tar.gz', $application->id(), $push->id())
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
