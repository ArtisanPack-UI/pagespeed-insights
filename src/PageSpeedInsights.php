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

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedClient;
use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;
use ArtisanPackUI\PageSpeedInsights\Data\TestResult;
use ArtisanPackUI\PageSpeedInsights\Exceptions\PageSpeedApiException;

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

    /**
     * The PageSpeed API client.
     *
     * Resolved on each call for the same reason as {@see self::config()}: the
     * client reads the configured driver, endpoint, and timeout when it is
     * built, and this class outlives all three.
     *
     * @since 1.0.0
     *
     * @return PageSpeedClient The API client.
     */
    public function client(): PageSpeedClient
    {
        return app( PageSpeedClient::class );
    }

    /**
     * Run a PageSpeed test synchronously.
     *
     * Blocks for as long as Google takes, which is 20-60 seconds and
     * sometimes longer, so this belongs in a queued job or a console command
     * rather than a web request.
     *
     * @since 1.0.0
     *
     * @param  string  $url  Absolute http(s) URL to test.
     * @param  string  $strategy  mobile or desktop.
     * @param  string|null  $locale  Optional BCP-47 locale for the returned strings.
     *
     * @throws PageSpeedApiException When the run could not be completed.
     *
     * @return TestResult The parsed result.
     */
    public function test( string $url, string $strategy = PageSpeedRequest::STRATEGY_MOBILE, ?string $locale = null ): TestResult
    {
        return $this->client()->test( $url, $strategy, $locale );
    }
}
