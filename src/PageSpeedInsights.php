<?php

/**
 * Main PageSpeedInsights class.
 *
 * Entry point for PageSpeed Insights testing, score history, and the UI
 * surfaces built on top of them. Accessed via the `pageSpeedInsights()`
 * helper function or the PageSpeedInsights facade.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights;

/**
 * Convenience aggregator for the PageSpeedInsights services.
 *
 * The scaffold exposes only the package version. The API client, URL
 * registry, and history readers are attached in the issues that build
 * them out.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class PageSpeedInsights
{
    /**
     * The package version. Kept in step with composer.json and the
     * CHANGELOG so consumers can feature-detect against a release.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const VERSION = '1.0.0';

    /**
     * The package version.
     *
     * @since 1.0.0
     *
     * @return string The semantic version of this package.
     */
    public function version(): string
    {
        return self::VERSION;
    }
}
