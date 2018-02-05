<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Utility;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Exception;
use Hal\Agent\Logger\EventLogger;
use Hal\Core\Crypto\Encryption;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\EncryptedProperty;
use Hal\Core\Entity\Environment;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolve properties about the build environment
 */
class EncryptedPropertyResolver
{
    const ERR_BAD_DECRYPT = 'Some properties could not be decrypted';
    const ERR_MISCONFIGURED_ENCRYPTION = 'A serious error occured while decrypting. HAL Agent may not be configured correctly.';

    /**
     * @var EntityRepository
     */
    private $encryptedRepo;

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ContainerInterface
     */
    private $di;

    /**
     * @var Encryption|null
     */
    private $encryption;

    /**
     * @param EntityManagerInterface $em
     * @param EventLogger $logger
     * @param ContainerInterface $di
     */
    public function __construct(
        EntityManagerInterface $em,
        EventLogger $logger,
        ContainerInterface $di
    ) {
        $this->encryptedRepo = $em->getRepository(EncryptedProperty::CLASS);
        $this->logger = $logger;
        $this->di = $di;
    }

    /**
     * @param array $encrypteds
     *
     * @return array
     */
    public function decryptProperties(array $encrypteds)
    {
        $decrypteds = [];

        // Handle empty encrypted list
        if (!$encrypteds) {
            return $decrypteds;
        }

        $encryption = $this->encryption();

        $bads = [];

        foreach ($encrypteds as $key => $encrypted) {
            try {
                $decrypted = $encryption->decrypt($encrypted);
                $decrypteds[$key] = $decrypted;

            } catch (Exception $ex) {
                $bad[] = $key;
            }
        }

        if ($bads) {
            $this->logger->event('failure', self::ERR_BAD_DECRYPT, [
                'invalidProperties' => $bads
            ]);
        }

        return $decrypteds;
    }

    /**
     * @param array $env
     * @param array $decrypteds
     *
     * @return array
     */
    public function mergePropertiesIntoEnv(array $env, array $decrypteds)
    {
        if ($decrypteds) {
            foreach ($decrypteds as $property => $decrypted) {
                $key = sprintf('ENCRYPTED_%s', strtoupper($property));
                $env[$key] = $decrypted;
            }
        }

        return $env;
    }

    /**
     * @param Application $application
     * @param Environment|null $environment
     *
     * @return array
     */
    public function getProperties(Application $application, ?Environment $environment)
    {
        if (is_null($environment)) {
            $environmentCriteria = Criteria::expr()->isNull('environment');
        } else {
            $environmentCriteria = Criteria::expr()->orX(
                Criteria::expr()->eq('environment', $environment),
                Criteria::expr()->isNull('environment')
            );
        }

        $criteria = (new Criteria)
            ->where(Criteria::expr()->eq('application', $application))
            ->andWhere($environmentCriteria)

            // null must be first!
            ->orderBy(['environment' => 'ASC']);

        $properties = $this->encryptedRepo->matching($criteria);

        if (count($properties) === 0) {
            return [];
        }

        $encrypted = [];
        foreach ($properties->toArray() as $property) {
            $encrypted[$property->name()] = $property;
        }

        ksort($encrypted);

        return $encrypted;
    }

    /**
     * @param Application $application
     * @param Environment|null $environment
     *
     * @return array
     */
    public function getEncryptedPropertiesWithSources(Application $application, ?Environment $environment)
    {
        $data = [
            'encrypted' => []
        ];

        if (!$properties = $this->getProperties($application, $environment)) {
            return $data;
        }

        $encrypted = $properties;
        $sources = $properties;

        // format encrypted for use by build step
        array_walk($encrypted, function (&$v) {
            $v = $v->data();
        });

        // format encrypted sources for logs
        array_walk($sources, function (&$v) {
            $from = 'Global';
            if ($env = $v->environment()) {
                $from = $env->name();
            }
            $v = sprintf('Resolved from %s', $from);
        });

        $data['encrypted'] = $encrypted;
        $data['encryptedSources'] = $sources;

        return $data;
    }

    /**
     * Lazy load the encryption from the symfony container so we can handle errors a bit better.
     *
     * @return Encryption|null
     */
    private function encryption()
    {
        if (!$this->encryption) {
            try {
                $this->encryption = $this->di->get('encryption');
            } catch (Exception $ex) {
                $this->logger->event('failure', self::ERR_MISCONFIGURED_ENCRYPTION);

                throw $ex;
            }
        }

        return $this->encryption;
    }
}
