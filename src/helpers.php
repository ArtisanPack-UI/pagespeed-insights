<?php

/**
 * PageSpeedInsights package helper functions.
 *
 * Global helper functions for the PageSpeedInsights package.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

use ArtisanPackUI\PageSpeedInsights\PageSpeedInsights;

if ( ! function_exists( 'pageSpeedInsights' ) ) {
    /**
     * Get the PageSpeedInsights instance.
     *
     * @since 1.0.0
     *
     * @return PageSpeedInsights The resolved package instance.
     */
    function pageSpeedInsights(): PageSpeedInsights
    {
        return app( 'pagespeed-insights' );
    }
}

// Add your custom helper functions below
