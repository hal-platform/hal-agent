<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Hal\Core\Entity\Job;
use Hal\Core\Entity\Target;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Credential\AWSRoleCredential;
use Hal\Core\Entity\Credential\AWSStaticCredential;
use Hal\Core\Parameters;
use Hal\Core\Type\CredentialEnum;
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
     * @param Clock $clock
     */
    public function __construct(Clock $clock)
    {
        $this->clock = $clock;
    }

    /**
     * @param Release $release
     *
     * @return array
     */
    public function __invoke(Release $release): array
    {
        $target = $release->target();

        $aws = [
            'region' => $target->parameter(Parameters::TARGET_REGION),
            'credential' => $target->credential() ? $this->getCredentials($target->credential()) : null
        ];

        $s3 = [
            'bucket' => $target->parameter(Parameters::TARGET_S3_BUCKET),
            'method' => $target->parameter(Parameters::TARGET_S3_METHOD),

            'local' => $target->parameter(Parameters::TARGET_S3_LOCAL_PATH) ?: static::DEFAULT_SRC,
            'remote' => $this->buildRemotePath($release)
        ];

        return [
            'aws' => $aws,
            's3' => $s3
        ];
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
        if ($template = $target->parameter(Parameters::PARAM_REMOTE_PATH)) {
            return $template;
        }

        if ($target->parameter(Parameters::PARAM_S3_METHOD) === 'sync') {
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
        $application = $job->application();
        $now = $this->clock->read();

        return [
            'JOBID' => $job->id(),

            'APPID' => $application->id(),
            'APP' => $application->name(),

            'DATE' => $now->format('Ymd', 'UTC'),
            'TIME' => $now->format('His', 'UTC')
        ];
    }

    /**
     * Get AWS Credentials out of Credential object
     *
     * @param Credential $credential
     *
     * @return AWSStaticCredential|AWSRoleCredential
     */
    private function getCredentials(Credential $credential)
    {
        if (!in_array($credential->type(), [CredentialEnum::TYPE_AWS_STATIC, CredentialEnum::TYPE_AWS_ROLE])) {
            return null;
        }

        return $credential->details();
    }
}
