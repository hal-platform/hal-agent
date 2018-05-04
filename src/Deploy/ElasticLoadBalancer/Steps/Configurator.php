<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticLoadBalancer\Steps;

use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Hal\Core\Parameters;

class Configurator
{
    /**
     * @var AWSAuthenticator
     */
    private $authenticator;

    /**
     * @param AWSAuthenticator $authenticator
     */
    public function __construct(AWSAuthenticator $authenticator)
    {
        $this->authenticator = $authenticator;
    }

    /**
     * @param Release $release
     *
     * @return ?array
     */
    public function __invoke(Release $release): ?array
    {
        $target = $release->target();
        $region = $target->parameter(Parameters::TARGET_REGION);

        if (![$elb, $ec2] = $this->authenticate($region, $target->credential())) {
            return null;
        }

        return [
            'sdk' => [
                'elb' => $elb,
                'ec2' => $ec2
            ],
            'region' => $region,
            'active_lb' => $target->parameter(Parameters::TARGET_ELB_ACTIVE),
            'passive_lb' => $target->parameter(Parameters::TARGET_ELB_PASSIVE),
            'ec2_tag' => $target->parameter(Parameters::TARGET_ELB_TAG)
        ];
    }

    /**
     * @param string $region
     * @param mixed $credential
     *
     * @return ?array
     */
    private function authenticate($region, $credential)
    {
        if (!$credential instanceof Credential) {
            return null;
        }

        $elb = $this->authenticator->getELB($region, $credential->details());

        $ec2 = $this->authenticator->getEC2($region, $credential->details());

        if (!$elb || !$ec2) {
            return null;
        }

        return [$elb, $ec2];
    }
}
