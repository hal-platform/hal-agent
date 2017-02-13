<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push;

trait HostnameValidatorTrait
{
    private $FQDN_SORTOF_PATTERN = '%s.rockfin.com';
    private $FQDN_PATTERN = '%s.mi.corp.rockfin.com';

    /**
     * Validate a hostname
     *
     * @param string $hostname
     *
     * @return string|null
     */
    private function validateHostname($hostname)
    {
        // Looks like an ip? Return it.
        if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $hostname;
        }

        // If the address is pingable, return the original hostname
        if ($hostname !== gethostbyname($hostname)) {
            return $hostname;
        }

        // Failed to reach the server, try the .rockfin address
        $hostname = sprintf($this->FQDN_SORTOF_PATTERN, $hostname);
        if ($hostname !== gethostbyname($hostname)) {
            return $hostname;
        }

        // Now we are desperate, try mi.corp.rockfin
        $hostname = sprintf($this->FQDN_PATTERN, $hostname);
        if ($hostname !== gethostbyname($hostname)) {
            return $hostname;
        }

        // we're in trouble now
        return null;
    }
}
