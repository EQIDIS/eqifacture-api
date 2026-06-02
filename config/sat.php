<?php

return [
    /*
    |--------------------------------------------------------------------------
    | TLS verification for the SAT portal (WEB scraper)
    |--------------------------------------------------------------------------
    | Set SAT_VERIFY_SSL=0 only for local diagnostics. Otherwise the scraper
    | uses the merged Mozilla + GlobalSign CA bundle.
    */
    'verify_ssl' => env('SAT_VERIFY_SSL', ''),

    /*
    |--------------------------------------------------------------------------
    | Webshare rotating residential proxy (WEB scraper only)
    |--------------------------------------------------------------------------
    | Base URL of the Webshare "Rotating Residential" endpoint, e.g.
    |   http://eocbexsl-MX:password@p.webshare.io:80
    | A numeric session token is appended per scrape (eocbexsl-MX-<n>) so each
    | download egresses from a different Mexican residential IP — avoiding the
    | SAT's per-IP rate limits. Leave empty to scrape from the server's own IP.
    */
    'webshare_proxy' => env('WEBSHARE_RESIDENTIAL_PROXY'),

    /*
    | How many times to retry a failed scrape on a DIFFERENT residential IP
    | (re-login + retry). Only applies when a proxy is configured.
    */
    'webshare_retries' => (int) env('WEBSHARE_PROXY_RETRIES', 3),
];
