<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\CodeDeploy\Steps;

use Aws\AwsClient;
use Hal\Agent\Deploy\S3\Steps\Configurator as S3Configurator;
use Hal\Agent\Logger\EventLogger;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Hal\Core\Parameters;
use QL\MCP\Common\Time\Clock;

class Configurator
{
    private const DEFAULT_SRC = '.';
    private const DEFAULT_FILE = '$APPID/$JOBID.tar.gz';

    private const ERR_CD = 'Could not authenticate with CodeDeploy';
    private const ERR_S3 = 'Could not authenticate with S3';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var AWSAuthenticator
     */
    private $authenticator;

    /**
     * @var string
     */
    private $halBaseURL;

    /**
     * @param EventLogger $logger
     * @param Clock $clock
     * @param AWSAuthenticator $authenticator
     */
    public function __construct(
        EventLogger $logger,
        Clock $clock,
        AWSAuthenticator $authenticator,
        string $halBaseURL
    ) {
        $this->logger = $logger;
        $this->clock = $clock;
        $this->authenticator = $authenticator;
        $this->halBaseURL = $halBaseURL;
    }

    /**
     * @param Release $release
     *
     * @return array|null
     */
    public function __invoke(Release $release): ?array
    {
        if (!$platformConfig = $this->getPlatformConfig($release)) {
            return null;
        }

        return $platformConfig;
    }

    /**
     * @param Release $release
     *
     * @return array|null
     */
    private function getPlatformConfig(Release $release)
    {
        $target = $release->target();
        $region = $target->parameter(Parameters::TARGET_REGION);

        if (!$s3 = $this->authenticate('s3', $region, $target->credential())) {
            $this->logger->event('failure', static::ERR_S3);
            return null;
        }

        if (!$cd = $this->authenticate('cd', $region, $target->credential())) {
            $this->logger->event('failure', static::ERR_CD);
            return null;
        }

        return [
            'sdk' => [
                's3' => $s3,
                'cd' => $cd
            ],
            'region' => $region,

            'bucket' => $target->parameter(Parameters::TARGET_S3_BUCKET),

            'application' => $target->parameter(Parameters::TARGET_CD_APP),
            'group' => $target->parameter(Parameters::TARGET_CD_GROUP),
            'configuration' => $target->parameter(Parameters::TARGET_CD_CONFIG),

            'local_path' => $target->parameter(Parameters::TARGET_S3_LOCAL_PATH) ?: static::DEFAULT_SRC,
            'remote_path' => $this->buildRemotePath($release),

            'deployment_description' => $this->buildDeploymentDescription($release)
        ];
    }

    /**
     * @param Release $release
     *
     * @return string
     */
    private function buildRemotePath(Release $release)
    {
        $target = $release->target();
        $template = $target->parameter(Parameters::TARGET_S3_REMOTE_PATH) ?: static::DEFAULT_FILE;
        $replacements = $this->buildTokenReplacements($release);

        foreach ($replacements as $name => $val) {
            $name = '$' . $name;
            $template = str_replace($name, $val, $template);
        }

        return $template;
    }

    /**
     * @param Release $release
     *
     * @return array
     */
    private function buildTokenReplacements(Release $release)
    {
        $application = $release->application();
        $environment = $release->environment();

        $now = $this->clock->read();

        return [
            'JOBID' => $release->id(),

            'APPID' => $application ? $application->id() : '',
            'APP' => $application ? $application->name() : '',

            'ENV' => $environment ? $environment->name() : '',

            'DATE' => $now->format('Ymd', 'UTC'),
            'TIME' => $now->format('His', 'UTC')
        ];
    }

    /**
     * @param Release $release
     *
     * @return string|null
     */
    private function buildDeploymentDescription(Release $release)
    {
        return sprintf('[%s]%s/%s/%s', $release->environment()->name(), $this->halBaseURL, $release->type(), $release->id());
    }

    /**
     * @param string $service
     * @param string $region
     * @param Credential|null $credential
     *
     * @return AwsClient|null
     */
    private function authenticate($service, $region, $credential)
    {
        if (!$credential instanceof Credential) {
            return null;
        }

        if ($service === 's3') {
            $client = $this->authenticator->getS3($region, $credential->details());
        } elseif ($service === 'cd') {
            $client = $this->authenticator->getCD($region, $credential->details());
        } else {
            return null;
        }

        return $client;
    }
}
