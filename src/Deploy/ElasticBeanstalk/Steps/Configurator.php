<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticBeanstalk\Steps;

use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Hal\Core\Parameters;
use QL\MCP\Common\Clock;

class Configurator
{
    private const DEFAULT_ARCHIVE_FILE = '$JOBID.tar.gz';
    private const DEFAULT_SRC = '.';

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
     * @param AWSAuthenticator $authenticator
     * @param Clock $clock
     */
    public function __construct(AWSAuthenticator $authenticator, Clock $clock, string $halBaseURL)
    {
        $this->authenticator = $authenticator;
        $this->clock = $clock;
        $this->halBaseURL = $halBaseURL;
    }

    /**
     * @param Release $release
     *
     * @return array|null
     */
    public function __invoke(Release $release): ?array
    {
        $target = $release->target();
        $region = $target->parameter(Parameters::TARGET_REGION);

        if (![$eb, $s3] = $this->authenticate($region, $target->credential())) {
            return null;
        }

        return [
            'sdk' => [
                's3' => $s3,
                'eb' => $eb
            ],
            'region' => $region,
            'application' => $target->parameter(Parameters::TARGET_EB_APP),
            'environment' => $target->parameter(Parameters::TARGET_EB_ENV),
            'bucket' => $target->parameter(Parameters::TARGET_S3_BUCKET),
            'local_path' => $target->parameter(Parameters::TARGET_S3_LOCAL_PATH) ?: static::DEFAULT_SRC,
            'remote_path' => $this->buildRemotePath($release),
            'deployment_description' => $this->buildDeploymentDescription($release)
        ];
    }

    /**
     * @param string $region
     * @param Credential|null $credential
     *
     * @return array|null
     */
    private function authenticate($region, $credential)
    {
        if (!$credential instanceof Credential) {
            return null;
        }
        $eb = $this->authenticator->getEB($region, $credential->details());
        $s3 = $this->authenticator->getS3($region, $credential->details());
        if (!$eb || !$s3) {
            return null;
        }
        return [$eb, $s3];
    }

    /**
     * @param Release $release
     *
     * @return string
     */
    private function buildRemotePath(Release $release)
    {
        $template = $this->getRemotePath($release->target());
        $replacements = $this->buildTokenReplacements($release);

        foreach ($replacements as $name => $val) {
            $name = '$' . $name;
            $template = str_replace($name, $val, $template);
        }

        return $template;
    }

    /**
     * @param Target $target
     *
     * @return string
     */
    private function getRemotePath(Target $target)
    {
        return  $target->parameter(Parameters::TARGET_S3_REMOTE_PATH) ?? static::DEFAULT_ARCHIVE_FILE;
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
     * @param Release $job
     *
     * @return array
     */
    private function buildTokenReplacements(Release $job)
    {
        $application = null;
        $environment = null;

        $application = $job->application();
        $environment = $job->environment();

        $now = $this->clock->read();

        return [
            'JOBID' => $job->id(),

            'APPID' => $application ? $application->id() : '',
            'APP' => $application ? $application->name() : '',

            'ENV' => $environment ? $environment->name() : '',

            'DATE' => $now->format('Ymd', 'UTC'),
            'TIME' => $now->format('His', 'UTC')
        ];
    }
}
