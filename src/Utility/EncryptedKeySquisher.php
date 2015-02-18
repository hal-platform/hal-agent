<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Utility;

/**
 * Strips all of the newlines and spaces out of an encrypted key, so I can store it in the config without it looking like poo.
 */
class EncryptedKeySquisher
{
    /**
     * @param string $data
     * @return string
     */
    public static function squish($data)
    {
        return str_replace("\n\t ", '', $data);
    }
}
