<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lazy command to proxy real console command
 */
class LazyCommand extends Command
{
    /**
     * @var Command|null
     */
    private $command;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var string
     */
    private $key;

    /**
     * The number of hits until a method will force a proxy load.
     *
     * @var array
     */
    private $timer = [
        'getDefinition' => 2
    ];

    /**
     * @param string $name
     * @param string $key
     * @param ContainerInterface $container
     */
    public function __construct($name, $key, ContainerInterface $container)
    {
        parent::__construct($name);

        $this->key = $key;
        $this->container = $container;
    }

    public function ignoreValidationErrors()
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    /**
     * lazy
     */
    public function setApplication(Application $application = null)
    {
        return $this->lazyProxy(__FUNCTION__, func_get_args());
    }

    /**
     * lazy
     */
    public function setHelperSet(HelperSet $helperSet)
    {
        return $this->lazyProxy(__FUNCTION__, func_get_args());
    }

    /**
     * lazy
     */
    public function getHelperSet()
    {
        return $this->lazyProxy(__FUNCTION__, func_get_args());
    }

    /**
     * lazy
     */
    public function getApplication()
    {
        return $this->lazyProxy(__FUNCTION__, func_get_args());
    }

    /**
     * lazy
     */
    public function isEnabled()
    {
        return $this->lazyProxy(__FUNCTION__, func_get_args());
    }

    public function run(InputInterface $input, OutputInterface $output)
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    public function setCode($code)
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    public function mergeApplicationDefinition($mergeArgs = true)
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    /**
     * lazy
     */
    public function setDefinition($definition)
    {
        return $this->lazyProxy(__FUNCTION__, func_get_args());
    }

    /**
     * lazy
     */
    public function getDefinition()
    {
        return $this->timerProxy(__FUNCTION__, func_get_args());
    }

    public function getNativeDefinition()
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    public function addArgument($name, $mode = null, $description = '', $default = null)
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    public function addOption($name, $shortcut = null, $mode = null, $description = '', $default = null)
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    /**
     * lazy
     */
    public function setName($name)
    {
        return $this->lazyProxy(__FUNCTION__, func_get_args());
    }

    /**
     * lazy
     */
    public function getName()
    {
        return $this->lazyProxy(__FUNCTION__, func_get_args());
    }

    public function setDescription($description)
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    public function getDescription()
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    public function setHelp($help)
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    public function getHelp()
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    public function getProcessedHelp()
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    /**
     * lazy
     */
    public function setAliases($aliases)
    {
        return $this->lazyProxy(__FUNCTION__, func_get_args());
    }

    /**
     * lazy
     */
    public function getAliases()
    {
        return $this->lazyProxy(__FUNCTION__, func_get_args());
    }

    public function getSynopsis($short = false)
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    public function getHelper($name)
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    public function asText()
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    public function asXml($asDom = false)
    {
        return $this->proxy(__FUNCTION__, func_get_args());
    }

    /**
     * Defer to the proxied command if the command is loaded.
     * If not loaded, call to parent.
     *
     * @param string $methodName
     * @param array $args
     * @return mixed
     */
    private function lazyProxy($methodName, $args)
    {
        if ($this->command === null) {
            return call_user_func_array('parent::' . $methodName, $args);
        }

        return $this->proxy($methodName, $args);
    }

    /**
     * Defer to the proxied command. Load the command if not loaded.
     *
     * @param string $methodName
     * @param array $args
     * @return mixed
     */
    private function proxy($methodName, $args)
    {
        return call_user_func_array([$this->command(), $methodName], $args);
    }

    /**
     * Defer to the proxied command if the command is loaded until the # of hits has been reached.
     * If not loaded, call to parent.
     *
     * @param string $methodName
     * @param array $args
     * @return mixed
     */
    private function timerProxy($methodName, $args)
    {
        if ($this->command !== null) {
            return $this->proxy($methodName, $args);
        }

        if ($this->timer[$methodName] > 0) {
            --$this->timer[$methodName];
            return call_user_func_array('parent::' . $methodName, $args);
        }

        return $this->proxy($methodName, $args);
    }

    /**
     * @return Command
     */
    private function command()
    {
        if ($this->command === null) {
            $command = $this->container->get($this->key);
            $command->setApplication($this->getApplication());
            $command->setHelperSet($this->getHelperSet());
            $command->setDefinition($command->getDefinition());
            $command->setAliases($this->getAliases());

            $this->command = $command;
        }

        return $this->command;
    }
}
