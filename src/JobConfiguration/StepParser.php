<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\JobConfiguration;

class StepParser
{
    /**
     * Organize a list of commands into an array such as
     * [
     *     [ $image1, [$command1, $command2] ]
     *     [ $image2, [$command3] ]
     *     [ $image1, [$command4] ]
     * ]
     *
     * @param string $defaultImageName
     * @param array $commands
     *
     * @return array
     */
    public function organizeCommandsIntoJobs($defaultImageName, array $commands)
    {
        $organized = [];
        $prevImage = null;
        foreach ($commands as $command) {
            list($image, $command) = $this->parseCommand($defaultImageName, $command);

            // Using same image in a row, rebuild the entire entry with the added command
            if ($image === $prevImage) {
                list($i, $cmds) = array_pop($organized);
                $cmds[] = $command;

                $entry = [$image, $cmds];

            } else {
                $entry = [$image, [$command]];
            }

            $organized[] = $entry;

            $prevImage = $image;
        }

        return $organized;
    }

    /**
     * This should return the docker image to use (WITHOUT "docker:" prefix), and command without docker instructions.
     *
     * @param string $defaultImage
     * @param string $command
     *
     * @return array [$imageName, $command]
     */
    private function parseCommand($defaultImage, $command)
    {
        // if (preg_match(self::$dockerPatternRegex, $command, $matches)) {
        //     $image = array_shift($matches);

        //     // Remove docker prefix from command
        //     $command = substr($command, strlen($image));

        //     // return docker image as just the "docker/*" part
        //     $image = substr($image, strlen(self::DOCKER_PREFIX));

        //     return [trim($image), trim($command)];
        // }

        return [$defaultImage, $command];
    }

}
