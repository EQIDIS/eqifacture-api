<?php

namespace App\Services;

/**
 * Builds per-scrape sticky URLs for the Webshare "Rotating Residential" proxy.
 *
 * The same residential IP is kept for a given (numeric) session token — sticky
 * for the whole SSO/scrape flow — while a fresh token on the next call yields a
 * different IP (rotation across scrapes/users). This spreads SAT requests over
 * many Mexican residential IPs, avoiding per-IP rate limits.
 *
 * Mirrors the EQIDIS Node backend's ProxyPoolService.
 */
class WebshareProxy
{
    /** True when a Webshare residential proxy is configured. */
    public static function enabled(): bool
    {
        return self::base() !== null;
    }

    private static function base(): ?string
    {
        $base = config('sat.webshare_proxy');

        return is_string($base) && $base !== '' ? $base : null;
    }

    /**
     * A per-scrape sticky residential proxy URL, or null to go direct.
     *
     *   http://eocbexsl-MX:pass@p.webshare.io:80
     *     -> http://eocbexsl-MX-<numericSession>:pass@p.webshare.io:80
     *
     * The session token MUST be numeric — Webshare rejects alphanumeric ones
     * with an authentication error.
     */
    public static function stickyUrl(): ?string
    {
        $base = self::base();
        if ($base === null) {
            return null;
        }

        if (! preg_match('#^(https?://)([^:@/]+):([^@/]+)@(.+)$#', $base, $m)) {
            // Unparseable; use as-is rather than breaking the scrape.
            return $base;
        }

        [, $scheme, $username, $password, $host] = $m;
        $session = random_int(0, 999_999_999);

        return "{$scheme}{$username}-{$session}:{$password}@{$host}";
    }

    /** A proxy URL with the password masked, for safe logging. */
    public static function mask(?string $url): string
    {
        if (! is_string($url) || $url === '') {
            return '(direct)';
        }

        return (string) preg_replace('#://([^:@/]+):[^@/]+@#', '://$1:***@', $url);
    }
}
