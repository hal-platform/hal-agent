<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Hal\Core\Parameters;
use QL\MCP\Common\Time\Clock;

class Configurator
{
    private const DEFAULT_ARCHIVE_FILE = '$JOBID.tar.gz';
    private const DEFAULT_SYNC_FILE = '.';
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
     * @param AWSAuthenticator $authenticator
     * @param Clock $clock
     */
    public function __construct(AWSAuthenticator $authenticator, Clock $clock)
    {
        $this->authenticator = $authenticator;
        $this->clock = $clock;
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

        if (!$s3 = $this->authenticate($region, $target->credential())) {
            return null;
        }

        return [
            'sdk' => [
                's3' => $s3
            ],

            'region' => $region,

            'bucket' => $target->parameter(Parameters::TARGET_S3_BUCKET),
            'method' => $target->parameter(Parameters::TARGET_S3_METHOD),

            'local_path' => $target->parameter(Parameters::TARGET_S3_LOCAL_PATH) ?: static::DEFAULT_SRC,
            'remote_path' => $this->buildRemotePath($release)
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

        $s3 = $this->authenticator->getS3($region, $credential->details());

        if (!$s3) {
            return null;
        }

        return $s3;
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
        if ($template = $target->parameter(Parameters::TARGET_S3_REMOTE_PATH)) {
            return $template;
        }

        if ($target->parameter(Parameters::TARGET_S3_METHOD) === 'sync') {
            return static::DEFAULT_SYNC_FILE;
        }

        return static::DEFAULT_ARCHIVE_FILE;
    }

    /**
     * @param Job $job
     *
     * @return array
     */
    private function buildTokenReplacements(Job $job)
    {
        $application = null;

        if ($job instanceof Build || $job instanceof Release) {
            $application = $job->application();
        }

        $now = $this->clock->read();

        return [
            'JOBID' => $job->id(),

            'APPID' => $application ? $application->id() : '',
            'APP' => $application ? $application->name() : '',

            'DATE' => $now->format('Ymd', 'UTC'),
            'TIME' => $now->format('His', 'UTC')
        ];
    }
}
