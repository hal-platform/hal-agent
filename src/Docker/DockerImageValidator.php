<?php
/**
 * @copyright (c) 2018 Steve Kluck
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Docker;

use Hal\Agent\Logger\EventLogger;

/**
 * Only valid windows containers:
 *
 * This means every other windows container MUST have one of these as their parent.
 *
 *  - microsoft/nanoserver          https://hub.docker.com/r/microsoft/nanoserver
 *  - microsoft/windowsservercore   https://hub.docker.com/r/microsoft/windowsservercore
 */
class DockerImageValidator
{
    const ERR_VALIDATE_FAILED = 'Invalid docker image specified';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * Pass a whitelist of allowed docker images. Empty list means no restrictions.
     *
     * @var array
     */
    private $allowedImages;

    /**
     * These may both be empty strings if you wish to allow the public Docker Hub
     *
     * @var string
     */
    private $easyRepositoryName;
    private $privateRegistry;

    /**
     * @param EventLogger $logger
     * @param array $allowedImages
     * @param string $easyRepositoryName
     * @param string $privateRegistry
     */
    public function __construct(EventLogger $logger, array $allowedImages, $easyRepositoryName, $privateRegistry)
    {
        $this->logger = $logger;
        $this->allowedImages = $allowedImages;
        $this->easyRepositoryName = $easyRepositoryName;
        $this->privateRegistry = $privateRegistry;
    }

    /**
     * @param string $selected
     *
     * @return string|null
     */
    public function validate($selected)
    {
        $original = $selected;

        // replace the "easy" repo with the "actual private registry"
        $selected = str_replace($this->easyRepositoryName, $this->privateRegistry, $selected);

        // If no whitelist set, allow everything
        if (!$this->allowedImages) {
            return $selected;
        }

        $selectedImage = $selected;
        $selectedTag = 'latest';

        $parts = explode(":", $selected);
        if (count($parts) == 2) {
            list($selectedImage, $selectedTag) = $parts;
        }

        $selected = "${selectedImage}:${selectedTag}";

        foreach ($this->allowedImages as $repo => $tags) {
            if ($selectedImage === $repo) {
                foreach ($tags as $tag) {
                    if ($tag === '*' || $selectedTag === $tag) {
                        return $selected;
                    }
                }
            }
        }

        $this->logger->event('failure', self::ERR_VALIDATE_FAILED, [
            'original' => $original,
            'specified' => $selected,
            'validImages' => $this->parseAllowed()
        ]);

        return null;
    }

    /**
     * @return array
     */
    private function parseAllowed()
    {
        $valid = [];
        foreach ($this->allowedImages as $image => $tags) {
            foreach ($tags as $tag) {
                $valid[] = "${image}:${tag}";
            }
        }

        return $valid;
    }
}
