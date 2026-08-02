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

    /*
    |--------------------------------------------------------------------------
    | Default Test Frequency
    |--------------------------------------------------------------------------
    |
    | How often a monitored URL is retested when its row carries no per-URL
    | override. Supported: "hourly", "daily", "weekly", "monthly". Anything
    | else falls back to "weekly".
    |
    | Bear the API quota in mind: each run costs one request per form factor,
    | so 200 URLs on both mobile and desktop is 400 requests per cycle.
    |
    */
    'test_frequency' => env( 'PAGESPEED_TEST_FREQUENCY', 'weekly' ),

    /*
    |--------------------------------------------------------------------------
    | Sitemap Discovery
    |--------------------------------------------------------------------------
    |
    | Settings for `php artisan pagespeed:discover-sitemap`, which populates
    | the monitored set from the site's own sitemap.
    |
    | - "url"     the sitemap to read. Null means sitemap.xml at app.url.
    | - "limit"   the most URLs one run will discover. The cap exists because
    |             a large site lists thousands of pages and every activated
    |             one costs quota on every cycle. Discovered URLs are stored
    |             inactive unless the command is run with --activate.
    | - "timeout" seconds to wait for a single sitemap document. Sitemap index
    |             files are followed, so one run may fetch several.
    |
    */
    'sitemap' => [
        'url'     => env( 'PAGESPEED_SITEMAP_URL' ),
        'limit'   => env( 'PAGESPEED_SITEMAP_LIMIT', 50 ),
        'timeout' => env( 'PAGESPEED_SITEMAP_TIMEOUT', 15 ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Store Raw Responses
    |--------------------------------------------------------------------------
    |
    | Whether to keep the full runPagespeed payload on each stored result.
    | Off by default: a single response runs to hundreds of kilobytes, so
    | retaining every one grows the history table by two orders of magnitude
    | for data the package already parses into its own columns.
    |
    | Worth turning on temporarily when diagnosing a parsing problem against
    | real responses.
    |
    */
    'store_raw_response' => env( 'PAGESPEED_STORE_RAW_RESPONSE', false ),

];
