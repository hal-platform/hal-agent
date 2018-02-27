<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\AWS;

use Hal\Core\Crypto\Encryption;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Credential\AWSRoleCredential;
use Hal\Core\Entity\Credential\AWSStaticCredential;
use Hal\Core\Entity\Target;
use Hal\Core\Type\CredentialEnum;

class Configurator
{
    /**
     * @var Encryption
     */
    private $encryption;

    public function __construct(Encryption $encryption)
    {
        $this->encryption = $encryption;
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
            'region' => $target->parameter(Target::PARAM_REGION),
            'credential' => $target->credential() ? $this->getCredentials($target->credential()) : null
        ];

        return ['aws' => $aws];
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
