<?php

/**
 * PageSpeedInsights package configuration.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

return [

    /*
    |--------------------------------------------------------------------------
    | PageSpeed Insights API Key
    |--------------------------------------------------------------------------
    |
    | REQUIRED. The PageSpeed Insights API will not run without a key: Google's
    | shared anonymous project has a daily quota of zero, so keyless requests
    | fail with HTTP 429 every time. There is no supported keyless or anonymous
    | mode.
    |
    | Create a key in the Google Cloud Console with the PageSpeed Insights API
    | enabled, then set PAGESPEED_API_KEY in your environment.
    |
    | This value is used by the default "config" driver. The "database" and
    | "cms" drivers ignore it and read the key from their own storage instead.
    |
    */
    'api_key' => env( 'PAGESPEED_API_KEY' ),

    /*
    |--------------------------------------------------------------------------
    | Configuration Driver
    |--------------------------------------------------------------------------
    |
    | Which driver backs the ApiKeyRepository. Supported: "config", "database",
    | "cms".
    |
    | - "config"   reads the api_key value above; read-only.
    | - "database" stores the key encrypted in the pagespeed_configurations
    |              table.
    | - "cms"      stores the key encrypted via the CMS framework's Settings
    |              module, and is only available when
    |              `artisanpack-ui/cms-framework` is installed.
    |
    */
    'driver' => env( 'PAGESPEED_CONFIG_DRIVER', 'config' ),

    /*
    |--------------------------------------------------------------------------
    | API Endpoint
    |--------------------------------------------------------------------------
    |
    | The runPagespeed endpoint. Google publishes two hosts for the same API:
    | the www.googleapis.com form used here (from Google's own Get Started
    | guide) and the canonical discovery form,
    | https://pagespeedonline.googleapis.com/pagespeedonline/v5/runPagespeed.
    | Either works.
    |
    */
    'endpoint' => env( 'PAGESPEED_ENDPOINT', 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' ),

    /*
    |--------------------------------------------------------------------------
    | Requested Categories
    |--------------------------------------------------------------------------
    |
    | Written in the response-key spelling (lower-kebab). The client translates
    | each entry to the API's upper-snake request enum (best-practices becomes
    | BEST_PRACTICES) and repeats the `category` query parameter once per
    | entry. Omitting the parameter entirely would return performance only,
    | which is a silent way to lose three scores.
    |
    | Lighthouse changes its category lineup roughly every two releases — PWA
    | is deprecated as of Lighthouse 12 and AGENTIC_BROWSING is new — so the
    | parser tolerates entries here that the response does not carry, and
    | response categories that are not listed here.
    |
    */
    'categories' => [ 'performance', 'accessibility', 'best-practices', 'seo' ],

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait for a single PageSpeed run. Google's own runs take 20-60
    | seconds and occasionally longer, so this sits well above a typical HTTP
    | timeout. Values that are not positive numbers fall back to 90.
    |
    */
    'timeout' => env( 'PAGESPEED_TIMEOUT', 90 ),

    /*
    |--------------------------------------------------------------------------
    | Opportunities Limit
    |--------------------------------------------------------------------------
    |
    | How many Lighthouse opportunities to keep from each run, ordered by
    | estimated savings. The full audit tree is far too large to store, and
    | the tail of it is not actionable. Consumers can reshape the pruned list
    | through the `ap.pageSpeed.opportunities` filter.
    |
    */
    'opportunities_limit' => 10,

];
