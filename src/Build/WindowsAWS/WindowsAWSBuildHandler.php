<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\Ec2\Ec2Client;
use Aws\S3\S3Client;
use Aws\Ssm\SsmClient;
use Hal\Agent\Build\PlatformInterface;
use Hal\Agent\Build\PlatformTrait;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\AWS\AWSAuthenticator;

/**
 * TODO TODO TODO TODO TODO TODO TODO TODO TODO TODO
 * TODO TODO TODO TODO TODO TODO TODO TODO TODO TODO
 *
 * - Automated setup of agents
 *     - hosts
 *     - docker latest
 *     - bsdtar
 *
 *  - [3.0] Multi-builder support?
 *  - [3.0] Multi-platform support?
 *  - [TODO] Verify logging the right messages/context at the right times
 *
 *  - [3.0] Impersonation / De-escalation of SYSTEM privileges   (Native Builder)
 *  - [3.0] Testing (failure states, breaking out)               (Native Builder)
 *
 * TODO TODO TODO TODO TODO TODO TODO TODO TODO TODO
 * TODO TODO TODO TODO TODO TODO TODO TODO TODO TODO
 */

/**
 * Note about windows AWS instance:
 *
 * - File management
 *     - Do not use 7z! It does not properly support targz (no pax and ustar)
 *     - Install LibArchive for Windows (http://gnuwin32.sourceforge.net/packages/libarchive.htm)
 *     - @todo automate this
 *
 * - Installing framework 3.5 (https://aws.amazon.com/premiumsupport/knowledge-center/net-framework-windows)
 *     - Find EBS Public Snapshot: "Windows 2016 English Installation Media"
 *     - Create Volume in same AZ as instances
 *     - Attach Volume to instance
 *     - RDP into server and bring volume "Online" In "Server Manager -> File Storage -> Volumes -> Disks"
 *     - Run "DISM /Online /Enable-Feature /FeatureName:NetFx3 /All /LimitAccess /Source:d:\sources\sxs"
 *     - Take Disk Offline
 *     - Detach and Delete Volume
 *
 * R&D references:
 *
 * - AWS Powershell cmdlets
 *     http://docs.aws.amazon.com/powershell/latest/reference/Index.html
 *
 * - System.Diagnostics.ProcessStartInfo
 *     https://msdn.microsoft.com/en-us/library/system.diagnostics.processstartinfo(v=vs.110).aspx
 * - System.Diagnostics.Process
 *     https://msdn.microsoft.com/en-us/library/system.diagnostics.process_methods(v=vs.110).aspx
 *
 * - LocalSystem Account
 *     https://msdn.microsoft.com/en-us/library/windows/desktop/ms684190(v=vs.85).aspx
 * - PsExec
 *     https://docs.microsoft.com/en-us/sysinternals/downloads/psexec
 * - SSM Agent source code
 *     https://github.com/aws/amazon-ssm-agent/blob/master/agent/plugins/runscript/runpowershellscript.go
 *
 * - Powershell cli help
 *     https://docs.microsoft.com/en-us/powershell/scripting/core-powershell/console/powershell.exe-command-line-help?view=powershell-5.1
 * - Start-Process cmdlet
 *     https://docs.microsoft.com/en-us/powershell/module/microsoft.powershell.management/start-process?view=powershell-5.1
 */
class WindowsAWSBuildHandler implements PlatformInterface
{
    // Comes with EmergencyBuildHandlerTrait, EnvironmentVariablesTrait, OutputAwareTrait
    use PlatformTrait;

    const SECTION = 'Building - Windows';
    const STATUS = 'Building on windows';
    const PLATFORM_TYPE = 'windows';

    const ERR_INVALID_BUILD_SYSTEM = 'Windows AWS build system is not configured';
    const ERR_BAD_DECRYPT = 'An error occured while decrypting encrypted properties';

    const STATUS_CLEANING = 'Cleaning up remote windows AWS artifacts';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var BuilderFinder
     */
    private $builderFinder;

    /**
     * @var Preparer
     */
    private $preparer;

    /**
     * @var Exporter
     */
    private $exporter;

    /**
     * @var BuilderInterface
     */
    private $builder;

    /**
     * @var Importer
     */
    private $importer;

    /**
     * @var Cleaner
     */
    private $cleaner;

    /**
     * @var AWSAuthenticator
     */
    private $authenticator;

    /**
     * @var EncryptedPropertyResolver
     */
    private $decrypter;

    /**
     * @var string
     */
    private $defaultDockerImage;

    /**
     * @param EventLogger $logger
     * @param BuilderFinder $builderFinder
     * @param Preparer $preparer
     * @param Exporter $exporter
     * @param BuilderInterface $builder
     * @param Importer $importer
     * @param Cleaner $cleaner
     * @param AWSAuthenticator $authenticator
     * @param EncryptedPropertyResolver $decrypter
     * @param string $defaultDockerImage
     */
    public function __construct(
        EventLogger $logger,
        BuilderFinder $builderFinder,
        Preparer $preparer,
        Exporter $exporter,
        BuilderInterface $builder,
        Importer $importer,
        Cleaner $cleaner,
        AWSAuthenticator $authenticator,
        EncryptedPropertyResolver $decrypter,
        $defaultDockerImage
    ) {
        $this->logger = $logger;

        $this->builderFinder = $builderFinder;
        $this->preparer = $preparer;
        $this->exporter = $exporter;
        $this->builder = $builder;
        $this->importer = $importer;
        $this->cleaner = $cleaner;

        $this->authenticator = $authenticator;
        $this->decrypter = $decrypter;

        $this->defaultDockerImage = $defaultDockerImage;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(array $commands, array $properties)
    {
        $this->status(self::STATUS, self::SECTION);

        // sanity check
        if (!$this->validate($properties)) {
            $this->logger->event('failure', self::ERR_INVALID_BUILD_SYSTEM);
            return 1;
        }

        // authenticate
        if (!$clients = $this->authenticate($properties)) {
            return 1;
        }

        list($s3, $ssm, $ec2) = $clients;

        if (!$instanceID = $this->findBuilderInstance($ec2, $properties)) {
            return 1;
        }

        if (!$this->prepare($ssm, $instanceID)) {
            return $this->bombout(1);
        }

        if (!$this->export($s3, $ssm, $instanceID, $properties)) {
            return $this->bombout(1);
        }

        // decrypt
        $decrypted = $this->decrypt($properties);
        if ($decrypted === null) {
            $this->logger->event('failure', self::ERR_BAD_DECRYPT);
            return $this->bombout(1);
        }

        // run build
        if (!$this->build($ssm, $instanceID, $properties, $commands, $decrypted)) {
            return $this->bombout(1);
        }

        if (!$this->import($s3, $ssm, $instanceID, $properties)) {
            return $this->bombout(1);
        }

        // success
        return $this->bombout(0);
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function validate($properties)
    {
        $this->status('Validating windows configuration', self::SECTION);

        if (!isset($properties[self::PLATFORM_TYPE])) {
            return false;
        }

        if (!$this->verifyConfiguration($properties[self::PLATFORM_TYPE])) {
            return false;
        }

        if (!$this->verifyPaths($properties['location'])) {
            return false;
        }

        return true;
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function verifyConfiguration($properties)
    {
        if (!is_array($properties)) {
            return false;
        }

        $required = [
            // aws
            'region',
            'credential',
            // ec2
            'instanceFilter',
            // s3
            'bucket',
            'objectInput',
            'objectOutput',
            // ssm
            'environmentVariables',
        ];

        foreach ($required as $prop) {
            if (!array_key_exists($prop, $properties)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function verifyPaths($properties)
    {
        if (!is_array($properties)) {
            return false;
        }

        $required = [
            'path',
            'windowsInputArchive',
            'windowsOutputArchive',
        ];

        foreach ($required as $prop) {
            if (!array_key_exists($prop, $properties)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array $properties
     *
     * @return array|null
     */
    private function authenticate(array $properties)
    {
        $this->status('Authenticating with AWS', self::SECTION);

        $s3 = $this->authenticator->getS3(
            $properties[self::PLATFORM_TYPE]['region'],
            $properties[self::PLATFORM_TYPE]['credential']
        );

        if (!$s3) {
            return null;
        }

        $ssm = $this->authenticator->getSSM(
            $properties[self::PLATFORM_TYPE]['region'],
            $properties[self::PLATFORM_TYPE]['credential']
        );

        if (!$ssm) {
            return null;
        }


        $ec2 = $this->authenticator->getEC2(
            $properties[self::PLATFORM_TYPE]['region'],
            $properties[self::PLATFORM_TYPE]['credential']
        );

        if (!$ec2) {
            return null;
        }

        return [$s3, $ssm, $ec2];
    }

    /**
     * @param Ec2Client $ec2
     * @param array $properties
     *
     * @return string|null
     */
    private function findBuilderInstance(Ec2Client $ec2, array $properties)
    {
        $finder = $this->builderFinder;
        return $finder($ec2, $properties[self::PLATFORM_TYPE]['instanceFilter']);
    }


    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @return bool
     */
    private function prepare(SsmClient $ssm, $instanceID)
    {
        $this->status('Preparing and validating build server', self::SECTION);

        $preparer = $this->preparer;
        return $preparer($ssm, $instanceID);
    }

    /**
     * @param S3Client $s3
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param array $properties
     *
     * @return bool
     */
    private function export(S3Client $s3, SsmClient $ssm, $instanceID, array $properties)
    {
        $this->status('Exporting files to AWS build server', self::SECTION);

        $localPath = $properties['location']['path'];
        $localFile = $properties['location']['windowsInputArchive'];

        $bucket = $properties[self::PLATFORM_TYPE]['bucket'];
        $file = $properties[self::PLATFORM_TYPE]['objectInput'];

        $buildID = $properties['build']->id();

        $exporter = $this->exporter;
        $response = $exporter($s3, $ssm, $instanceID, $localPath, $localFile, $bucket, $file, $buildID);

        $bucket = $properties[self::PLATFORM_TYPE]['bucket'];
        $artifacts = [
            $properties[self::PLATFORM_TYPE]['objectInput'],
            $properties[self::PLATFORM_TYPE]['objectOutput']
        ];

        if ($response) {
            // Set emergency handler in case of super fatal
            $cleaningArgs = [$s3, $ssm, $instanceID, $bucket, $artifacts, $buildID];
            $this->enableEmergencyHandler($this->cleaner, self::STATUS_CLEANING, $cleaningArgs);
        }

        return $response;
    }

    /**
     * @param array $properties
     *
     * @return array|null
     */
    private function decrypt(array $properties)
    {
        if (!isset($properties['encrypted'])) {
            return [];
        }

        $decrypted = $this->decrypter->decryptProperties($properties['encrypted']);
        if (count($decrypted) !== count($properties['encrypted'])) {
            return null;
        }

        return $decrypted;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param array $properties
     * @param array $commands
     * @param array $decrypted
     *
     * @return bool
     */
    private function build(SsmClient $ssm, $instanceID, array $properties, array $commands, array $decrypted)
    {
        $this->status('Running build command', self::SECTION);

        $dockerImage = $properties['configuration']['image'] ? $properties['configuration']['image'] : $this->defaultDockerImage;

        $buildID = $properties['build']->id();

        $env = $this->determineEnviroment(
            $properties[self::PLATFORM_TYPE]['environmentVariables'],
            $decrypted,
            $properties['configuration']['env']
        );

        $builder = $this->builder;

        if ($this->getOutput()) {
            $builder->setOutput($this->getOutput());
        }

        return $builder($ssm, $dockerImage, $instanceID, $buildID, $commands, $env);
    }

    /**
     * @param S3Client $s3
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param array $properties
     *
     * @return bool
     */
    private function import(S3Client $s3, SsmClient $ssm, $instanceID, array $properties)
    {
        $this->status('Importing files from AWS build server', self::SECTION);

        $localPath = $properties['location']['path'];
        $localFile = $properties['location']['windowsOutputArchive'];

        $bucket = $properties[self::PLATFORM_TYPE]['bucket'];
        $object = $properties[self::PLATFORM_TYPE]['objectOutput'];

        $buildID = $properties['build']->id();

        $importer = $this->importer;
        return $importer($s3, $ssm, $instanceID, $localPath, $localFile, $bucket, $object, $buildID);
    }
}
