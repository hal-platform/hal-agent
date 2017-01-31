<?php
/**
 * @copyright ©2017 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */
namespace QL\Hal\Agent\Symfony;

use PHPUnit_Framework_TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use Symfony\Component\Yaml\Yaml;

class YamlTest extends PHPUnit_Framework_TestCase
{
    /**
     * Tests to make sure a parse exception isn't thrown on our yaml configs
     */
    public function testYamlFilesDoNotThrowErrorsWhenPared()
    {
        $configFiles = new RecursiveDirectoryIterator(__DIR__ . '/../../../configuration');
        $iterator = new RecursiveIteratorIterator($configFiles);
        $yamlRegex = new RegexIterator($iterator, '/^.+\.yml$/i', RecursiveRegexIterator::GET_MATCH);

        /** @var  $yamlFile */
        foreach ($yamlRegex as $yamlRegexMatch) {
            $yamlFilePath = array_pop($yamlRegexMatch);
            $this->assertNotNull(Yaml::parse(file_get_contents($yamlFilePath)));
        }
    }

    public function testVendorImportsYamlsAreCorrect()
    {
        $configDirPath = __DIR__ . '/../../../configuration';
        $config = Yaml::parse(file_get_contents( $configDirPath . '/config.yml'));

        foreach ($config['imports'] as $resource){
            foreach ($resource as $resourcePath) {
                if (preg_match('/\/vendor\/.+\.yml$/', $resourcePath)) {
                    $this->assertNotNull(Yaml::parse(file_get_contents($configDirPath . DIRECTORY_SEPARATOR . $resourcePath)));
                }
            }
        }
    }
}
