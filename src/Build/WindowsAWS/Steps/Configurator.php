<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS\Steps;

use Doctrine\ORM\EntityManagerInterface;
use Hal\Agent\Build\EnvironmentVariablesTrait;
use Hal\Agent\Build\WindowsAWS\AWS\BuilderFinder;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Job;

class Configurator
{
    use EnvironmentVariablesTrait;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var AWSAuthenticator
     */
    private $authenticator;

    /**
     * @var BuilderFinder
     */
    private $builderFinder;

    /**
     * @var string
     */
    private $windowsRegion;
    private $windowsCredentialName;
    private $windowsBucket;
    private $windowsInstanceFilter;

    /**
     * @param EntityManagerInterface $em
     * @param AWSAuthenticator $authenticator
     * @param BuilderFinder $builderFinder
     *
     * @param string $region
     * @param string $credentialName
     * @param string $bucket
     * @param string $tagFilter
     */
    public function __construct(
        EntityManagerInterface $em,
        AWSAuthenticator $authenticator,
        BuilderFinder $builderFinder,
        string $region,
        string $credentialName,
        string $bucket,
        string $tagFilter
    ) {
        $this->em = $em;
        $this->authenticator = $authenticator;
        $this->builderFinder = $builderFinder;

        $this->windowsRegion = $region;
        $this->windowsCredentialName = $credentialName;
        $this->windowsBucket = $bucket;
        $this->windowsInstanceFilter = $tagFilter;
    }

    /**
     * @param Job $job
     *
     * @return array|null
     */
    public function __invoke(Job $job): ?array
    {
        if (!$clients = $this->authenticate($this->windowsRegion, $this->windowsCredentialName)) {
            return null;
        }

        list($s3, $ssm, $ec2) = $clients;

        $instanceID = ($this->builderFinder)($ec2, $this->windowsInstanceFilter);
        if (!$instanceID) {
            return null;
        }

        return [
            'instance_id' => $instanceID,

            'sdk' => [
                's3' => $s3,
                'ssm' => $ssm
            ],

            'bucket' => $this->windowsBucket,
            's3_input_object' => $this->generateS3ObjectName($job->id(), 'input'),
            's3_output_object' => $this->generateS3ObjectName($job->id(), 'output'),

            'environment_variables' => $this->buildEnvironmentVariables($job)
        ];
    }

    /**
     * @param string $region
     * @param string $credentialName
     *
     * @return array|null
     */
    private function authenticate($region, $credentialName)
    {
        $credential = $this->em
            ->getRepository(Credential::class)
            ->findOneBy(['isInternal' => true, 'name' => $credentialName]);

        if (!$credential instanceof Credential) {
            return null;
        }

        $s3 = $this->authenticator->getS3($region, $credential->details());
        $ssm = $this->authenticator->getSSM($region, $credential->details());
        $ec2 = $this->authenticator->getEC2($region, $credential->details());

        if (!$s3 || !$ssm || !$ec2) {
            return null;
        }

        return [$s3, $ssm, $ec2];
    }

    /**
     * @param string $uniqueID
     * @param string $type
     *
     * @return string
     */
    private function generateS3ObjectName($uniqueID, $type)
    {
        return sprintf('hal-job-%s-%s.tgz', $uniqueID, $type);
    }
}
