<?php

namespace Tests\Unit;

use App\Services\WebshareProxy;
use Tests\TestCase;

class WebshareProxyTest extends TestCase
{
    public function test_disabled_when_no_proxy_configured(): void
    {
        config(['sat.webshare_proxy' => null]);

        $this->assertFalse(WebshareProxy::enabled());
        $this->assertNull(WebshareProxy::stickyUrl());
    }

    public function test_appends_numeric_session_keeping_password_and_host(): void
    {
        config(['sat.webshare_proxy' => 'http://eocbexsl-MX:secret@p.webshare.io:80']);

        $url = WebshareProxy::stickyUrl();

        // Session token MUST be numeric (Webshare rejects alphanumeric ones).
        $this->assertMatchesRegularExpression(
            '#^http://eocbexsl-MX-[0-9]+:secret@p\.webshare\.io:80$#',
            $url
        );
    }

    public function test_rotates_ip_on_each_call(): void
    {
        config(['sat.webshare_proxy' => 'http://eocbexsl-MX:secret@p.webshare.io:80']);

        $this->assertNotSame(WebshareProxy::stickyUrl(), WebshareProxy::stickyUrl());
    }

    public function test_masks_password_for_logging(): void
    {
        $this->assertSame(
            'http://eocbexsl-MX-5:***@p.webshare.io:80',
            WebshareProxy::mask('http://eocbexsl-MX-5:secret@p.webshare.io:80')
        );
        $this->assertSame('(direct)', WebshareProxy::mask(null));
    }
}
