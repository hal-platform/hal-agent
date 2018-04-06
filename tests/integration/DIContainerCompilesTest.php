<?php

namespace Hal\Agent\Symfony;

use Hal\Agent\Application\DI;
use Hal\Agent\CachedContainer;
use Hal\Agent\Testing\MockeryTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;

class ContainerIntegrationTest extends MockeryTestCase
{
    private $rootPath;
    private $envFile;

    public function setUp()
    {
        $this->rootPath = realpath(__DIR__ . '/../..');
        $this->envFile = "{$this->rootPath}/config/.env.default";

        $_ENV['HAL_ROOT'] = "{$this->rootPath}";
        $_ENV['HAL_DI_DISABLE_CACHE_ON'] = "1";
        putenv("HAL_ROOT={$this->rootPath}");
        putenv("HAL_DI_DISABLE_CACHE_ON=1");
    }

    public function tearDown()
    {
        unset($_ENV['HAL_ROOT']);
        unset($_ENV['HAL_DI_DISABLE_CACHE_ON']);
    }

    /**
     * Tests to make sure a parse exception isn't thrown on our yaml configs
     */
    public function testContainerCompiles()
    {
        $dotenv = new Dotenv;
        $dotenv->load($this->envFile);

        $options = [
            'class' => CachedContainer::class,
            'file' => "{$this->rootPath}/src/CachedContainer.php"
        ];

        $container = DI::getDI($this->rootPath, $options);

        $this->assertInstanceOf(ContainerInterface::class, $container);
    }
}
