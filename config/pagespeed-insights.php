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

];
