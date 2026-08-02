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

use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;

/**
 * Convenience aggregator for the PageSpeedInsights services.
 *
 * The scaffold exposes the package version and the API key repository. The
 * API client, URL registry, and history readers are attached in the issues
 * that build them out.
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

    /**
     * The API key repository backing the configured storage driver.
     *
     * Resolved on each call rather than injected so that a driver change
     * (`config( 'pagespeed-insights.driver' )`) takes effect immediately —
     * this class is a long-lived singleton.
     *
     * @since 1.0.0
     *
     * @return ApiKeyRepository The repository for the configured driver.
     */
    public function config(): ApiKeyRepository
    {
        return app( ApiKeyRepository::class );
    }
}
