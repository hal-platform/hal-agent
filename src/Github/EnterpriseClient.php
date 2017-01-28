<?php
/**
 * @copyright ©2017 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */
namespace QL\Hal\Agent\Github;

use Github\Client;
use Github\HttpClient\Plugin\PathPrepend;
use Http\Client\Common\Plugin;
use Http\Client\Common\Plugin\AddHostPlugin;
use Http\Discovery\UriFactoryDiscovery;

class EnterpriseClient extends Client
{
    public function __construct($httpClientBuilder, $apiVersion, $enterpriseUrl)
    {
        parent::__construct($httpClientBuilder, $apiVersion, $enterpriseUrl);
        $this->getHttpClientBuilder()->removePlugin(AddHostPlugin::class);
        $this->getHttpClientBuilder()->removePlugin(PathPrepend::class);
        $this->getHttpClientBuilder()->addPlugin(new AddHostPlugin(UriFactoryDiscovery::find()->createUri($enterpriseUrl)));
        $this->getHttpClientBuilder()->addPlugin(new PathPrepend(sprintf('/api/%s', $this->getApiVersion())));
    }

    /**
     * Some of the knplab api methods require certain plugins to be on the http clients
     * Like the History plugin for the ResultPager. But if you know better use this to conditionally
     * remove and add plugins
     *
     * @param $pluginClassName
     */
    public function removePlugin($pluginClassName)
    {
        $this->getHttpClientBuilder()->removePlugin($pluginClassName);
    }

    public function addPlugin(Plugin $plugin)
    {
        $this->getHttpClientBuilder()->addPlugin($plugin);
    }
}
