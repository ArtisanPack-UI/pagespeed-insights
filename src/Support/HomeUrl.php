<?php

/**
 * The application's own home page, as a testable URL.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Support;

use ArtisanPackUI\PageSpeedInsights\Urls\UrlNormalizer;

/**
 * Resolves the URL a surface should describe when nobody named one.
 *
 * This exists for the CMS-framework dashboard widgets, which are placed by
 * an administrator through a widget picker that has nowhere to type a URL:
 * a widget dropped on a dashboard with no options set has to describe
 * *something*, and the only address the package can know is the one the
 * application says it is served at.
 *
 * A widget that quietly described an address nobody asked about would be
 * worse than one that described nothing, so the resolution is deliberately
 * narrow. `app.url` is normalized through the same normalizer as every
 * stored URL — which is what makes the widget read the same history row the
 * monitored home page writes, rather than a near-miss spelling of it — and
 * an `app.url` that is not a testable http(s) address resolves to an empty
 * string. The components already have an honest state for that, and it says
 * the card has no URL rather than inventing one.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
final class HomeUrl
{
    /**
     * The application's home page, canonicalized.
     *
     * @since 1.0.0
     *
     * @return string The normalized home URL, or an empty string when `app.url` is unusable.
     */
    public static function resolve(): string
    {
        $appUrl = config( 'app.url' );

        if ( ! is_string( $appUrl ) || '' === trim( $appUrl ) ) {
            return '';
        }

        return UrlNormalizer::normalize( $appUrl ) ?? '';
    }

    /**
     * The given URL, or the application's home page when none was given.
     *
     * Null is accepted as well as an empty string so that a stored widget
     * whose options were written by hand — or by a future version of the
     * dashboard — resolves to the home page rather than to a type error.
     *
     * @since 1.0.0
     *
     * @param  string|null  $url  The URL a caller asked for, which may be empty or null.
     *
     * @return string The URL to use.
     */
    public static function or( ?string $url ): string
    {
        return '' === trim( (string) $url ) ? self::resolve() : (string) $url;
    }
}
