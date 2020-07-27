<?php
/**
 * This file is part of the Shieldon package.
 *
 * (c) Terry L. <contact@terryl.in>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 * php version 7.1.0
 * 
 * @category  Web-security
 * @package   Shieldon
 * @author    Terry Lin <contact@terryl.in>
 * @copyright 2019 terrylinooo
 * @license   https://github.com/terrylinooo/shieldon/blob/2.x/LICENSE MIT
 * @link      https://github.com/terrylinooo/shieldon
 * @see       https://shieldon.io
 */

declare(strict_types=1);

namespace Shieldon\FirewallTest\Panel;

class SecurityTest extends \Shieldon\FirewallTest\ShieldonTestCase
{
    use RouteTestTrait;

    public function testWebAuthentication()
    {
        $this->assertPageOutputContainsString(
            'firewall/panel/security/authentication',
            'Web Page Authentication'
        );
    }

    public function testXssProtection()
    {
        $this->assertPageOutputContainsString(
            'firewall/panel/security/xssProtection',
            'XSS Protection'
        );
    }
}