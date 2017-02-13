<?php
/**
 * @copyright ©2017 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */
namespace Hal\Agent\Github;

use Github\Client;
use Github\HttpClient\Plugin\PathPrepend;
use Http\Client\Common\Plugin;
use Http\Client\Common\Plugin\AddHostPlugin;
use function GuzzleHttp\Psr7\uri_for;

/**
 * @todo update to knplabs/php-github-api v2.0.2 when it is released
 * https://github.com/KnpLabs/php-github-api/releases
 */
class EnterpriseClient extends Client
{
    public function __construct($httpClientBuilder, $apiVersion, $enterpriseUrl)
    {
        parent::__construct($httpClientBuilder, $apiVersion);

        $httpClientBuilder->removePlugin(AddHostPlugin::class);
        $httpClientBuilder->removePlugin(PathPrepend::class);

        $httpClientBuilder->addPlugin(new AddHostPlugin(uri_for($enterpriseUrl)));
        $httpClientBuilder->addPlugin(new PathPrepend(sprintf('/api/%s', $this->getApiVersion())));
    }
}
