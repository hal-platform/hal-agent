<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Utility;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Hal\Agent\Logger\EventLogger;
use Hal\Core\Crypto\Encryption;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\EncryptedProperty;
use Hal\Core\Entity\Environment;
use Hal\Core\Repository\EncryptedPropertyRepository;

/**
 * Resolve properties about the build environment
 */
class EncryptedPropertyResolver
{
    const ERR_BAD_DECRYPT = 'Some properties could not be decrypted';

    /**
     * @var EncryptedPropertyRepository
     */
    private $encryptedRepo;

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Encryption
     */
    private $encryption;

    /**
     * @param EntityManagerInterface $em
     * @param EventLogger $logger
     * @param Encryption $encryption
     */
    public function __construct(
        EntityManagerInterface $em,
        Encryption $encryption,
        EventLogger $logger
    ) {
        $this->encryptedRepo = $em->getRepository(EncryptedProperty::class);
        $this->encryption = $encryption;

        $this->logger = $logger;
    }

    /**
     * @param array $encrypteds
     *
     * @return array
     */
    public function decryptProperties(array $encrypteds): array
    {
        $decrypteds = [];

        // Handle empty encrypted list
        if (!$encrypteds) {
            return $decrypteds;
        }

        $bads = [];

        foreach ($encrypteds as $key => $encrypted) {
            try {
                $decrypted = $this->encryption->decrypt($encrypted);
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
     * @param Application $application
     * @param Environment|null $environment
     *
     * @return array
     */
    public function getEncryptedPropertiesWithSources(Application $application, ?Environment $environment): array
    {
        $data = [
            'encrypted' => [],
            'sources' => []
        ];

        if (!$properties = $this->encryptedRepo->getPropertiesForEnvironment($application, $environment)) {
            return $data;
        }

        $encrypted = $properties;
        $sources = $properties;

        // format encrypted for use by build step
        array_walk($encrypted, function (&$v) {
            $v = $v->secret();
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
        $data['sources'] = $sources;

        return $data;
    }
}
