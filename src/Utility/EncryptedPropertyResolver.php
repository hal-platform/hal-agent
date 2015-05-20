<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Utility;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityRepository;
use Exception;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Core\Crypto\Decrypter;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Repository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolve properties about the build environment
 */
class EncryptedPropertyResolver
{
    const ERR_BAD_DECRYPT = 'Some properties could not be decrypted';
    const ERR_MISCONFIGURED_ENCRYPTION = 'A serious error occured while decrypting. HAL Agent may not be configured correctly.';

    /**
     * @type EntityRepository
     */
    private $encryptedRepo;

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type ContainerInterface
     */
    private $di;

    /**
     * @type Decrypter|null
     */
    private $decrypter;

    /**
     * @param EntityRepository $encryptedRepo
     * @param EventLogger $logger
     * @param ContainerInterface $di
     */
    public function __construct(
        EntityRepository $encryptedRepo,
        EventLogger $logger,
        ContainerInterface $di
    ) {
        $this->encryptedRepo = $encryptedRepo;
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

        $decrypter = $this->decrypter();

        $bads = [];

        foreach ($encrypteds as $key => $encrypted) {
            try {
                $decrypted = $decrypter->decrypt($encrypted);
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
     * @param Repository $repo
     * @param Environment $environment
     *
     * @return array
     */
    public function getProperties(Repository $repo, Environment $environment)
    {
        $criteria = (new Criteria)
            ->where(Criteria::expr()->eq('repository', $repo))
            ->andWhere(
                Criteria::expr()->orX(
                    Criteria::expr()->eq('environment', $environment),
                    Criteria::expr()->isNull('environment')
                )
            )

            // null must be first!
            ->orderBy(['environment' => 'ASC']);

        $properties = $this->encryptedRepo->matching($criteria);

        if (count($properties) === 0) {
            return [];
        }

        $encrypted = [];
        foreach ($properties->toArray() as $property) {
            $encrypted[$property->getName()] = $property;
        }

        ksort($encrypted);

        return $encrypted;
    }

    /**
     * @param Repository $repository
     * @param Environment $environment
     *
     * @return array
     */
    public function getEncryptedPropertiesWithSources(Repository $repository, Environment $environment)
    {
        $data = [
            'encrypted' => []
        ];

        if (!$properties = $this->getProperties($repository, $environment)) {
            return $data;
        }

        $encrypted = $properties;
        $sources = $properties;

        // format encrypted for use by build step
        array_walk($encrypted, function(&$v) {
            $v = $v->getData();
        });

        // format encrypted sources for logs
        array_walk($sources, function(&$v) {

            $from = 'global';
            if ($env = $v->getEnvironment()) {
                $from = $env->getKey();
            }
            $v = sprintf('Resolved from %s', $from);
        });

        $data['encrypted'] = $encrypted;
        $data['encryptedSources'] = $sources;

        return $data;
    }

    /**
     * Lazy load the decrypter from the symfony container so we can handle errors a bit better.
     *
     * @return Decrypter|null
     */
    private function decrypter()
    {
        if (!$this->decrypter) {
            try {
                $this->decrypter = $this->di->get('decrypter');
            } catch (Exception $ex) {
                $this->logger->event('failure', self::ERR_MISCONFIGURED_ENCRYPTION);

                throw $ex;
            }
        }

        return $this->decrypter;
    }
}
