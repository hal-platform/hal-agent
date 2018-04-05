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
        $this->envFile = "{$this->rootPath}/config/.env.dist";

        putenv("HAL_ROOT={$this->rootPath}");
    }

    public function tearDown()
    {
        putenv("HAL_ROOT=");
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
