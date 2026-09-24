<?php

declare(strict_types=1);

namespace Tests\Controllers;

use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\UserAgents;

/**
 * D26: a phone login with order tickets lands on the order screen (Employee::landing_route()).
 *
 * The rule itself is covered by EmployeeLandingRouteTest. This file covers the two things it relies
 * on: that this system's user-agent configuration really recognises the phones a waiter carries, and
 * not a computer; and that every login path hands that answer to landing_route(). A path that forgot
 * would silently send phone logins home.
 *
 * @internal
 */
final class LoginPhoneDetectionTest extends CIUnitTestCase
{
    private const IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1';
    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36';
    private const MAC     = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Safari/605.1.15';
    private const WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    public function testPhonesAreRecognised(): void
    {
        $this->assertTrue($this->agent(self::IPHONE)->isMobile(), 'iPhone');
        $this->assertTrue($this->agent(self::ANDROID)->isMobile(), 'Android');
    }

    public function testComputersAreNot(): void
    {
        $this->assertFalse($this->agent(self::MAC)->isMobile(), 'Mac');
        $this->assertFalse($this->agent(self::WINDOWS)->isMobile(), 'Windows');
    }

    /**
     * index() (the ordinary login), totp() (second factor) and the platform support entry: all three
     * pass from_phone(). None may call landing_route() with one argument.
     */
    public function testEveryLoginPathPassesWhetherItIsAPhone(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Controllers/Login.php');

        $this->assertSame(3, substr_count($source, '->landing_route('));
        $this->assertSame(3, preg_match_all('/->landing_route\([^;]*\$this->from_phone\(\)\)/', $source));
    }

    private function agent(string $string): UserAgent
    {
        $agent = new UserAgent(new UserAgents());
        $agent->parse($string);

        return $agent;
    }
}
