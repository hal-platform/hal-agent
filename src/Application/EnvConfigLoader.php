<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Application;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Container Modifier for use when loading the container, or dumping the compiled container.
 */
class EnvConfigLoader
{
    /**
     * @param ContainerInterface $container
     *
     * @return void
     */
    public function load(ContainerInterface $container)
    {
        // Save database password from encrypted properties on HAL 9000 deployment
        if (false !== ($property = getenv('ENCRYPTED_HAL_DB_PASS'))) {
            $container->setParameter('database.password', $property);
        }

        // Save commit SHA on HAL 9000 deployment
        if (false !== ($property = getenv('HAL_COMMIT'))) {
            $container->setParameter('application.sha', $property);
        }
    }
}
