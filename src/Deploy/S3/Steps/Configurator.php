<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Hal\Core\Crypto\Encryption;
use Hal\Core\Entity\Target;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Credential\AWSRoleCredential;
use Hal\Core\Entity\Credential\AWSStaticCredential;
use Hal\Core\Type\CredentialEnum;
use Hal\Core\Type\TargetEnum;
use QL\MCP\Common\Time\Clock;

class Configurator
{
    private const DEFAULT_ARCHIVE_FILE = '$PUSHID.tar.gz';
    private const DEFAULT_SYNC_FILE = '.';
    private const DEFAULT_SRC = '.';

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var Encryption
     */
    private $encryption;

    /**
     * @param Clock $clock
     */
    public function __construct(Encryption $encryption, Clock $clock)
    {
        $this->encryption = $encryption;
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

        $replacements = $this->buildTokenReplacements($release);
        $template = $this->getTemplate($target);

        $aws = [
            'region' => $target->parameter(Target::PARAM_REGION),
            'credential' => $target->credential() ? $this->getCredentials($target->credential()) : null
        ];

        $s3 = [
            'bucket' => $target->parameter(Target::PARAM_BUCKET),
            'strategy' => $target->parameter(Target::PARAM_S3_METHOD),
            'file' => $this->buildFilename($replacements, $template),
            'src' => $target->parameter(Target::PARAM_LOCAL_PATH) ?: self::DEFAULT_SRC
        ];

        return [
            'aws' => $aws,
            's3' => $s3
        ];
    }

    /**
     * @param array $replacements
     * @param string $template
     *
     * @return string
     */
    private function buildFilename(array $replacements, $template)
    {
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
    private function getTemplate(Target $target)
    {
        $template = $target->parameter(Target::PARAM_REMOTE_PATH);
        if (!$template) {
            $template = $target->parameter(Target::PARAM_S3_METHOD) === 'sync' ? self::DEFAULT_SYNC_FILE : self::DEFAULT_ARCHIVE_FILE;
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
        $build = $release->build();

        $now = $this->clock->read();
        return [
            'APPID' => $application->id(),
            'APP' => $application->name(),
            'BUILDID' => $build->id(),
            'PUSHID' => $release->id(),
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
