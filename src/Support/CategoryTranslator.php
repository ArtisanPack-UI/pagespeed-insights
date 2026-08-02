<?php

/**
 * Lighthouse category spelling translation.
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

/**
 * Translates Lighthouse category names between the two spellings PageSpeed
 * uses for the same thing.
 *
 * The request enum is upper-snake (`BEST_PRACTICES`); the response nests the
 * same category under a lower-kebab key
 * (`lighthouseResult.categories["best-practices"]`). Getting this backwards
 * silently loses scores, so the translation lives in one place instead of
 * being inlined at each call site.
 *
 * The conversion is mechanical rather than a lookup table on purpose. The
 * live enum already carries values the plan never anticipated
 * (`AGENTIC_BROWSING`) and one that is on its way out (`PWA`, deprecated in
 * Lighthouse 12), so anything hard-coded to a fixed list of four would need
 * editing every time Lighthouse moves.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class CategoryTranslator
{
    /**
     * The four categories this package stores as dedicated scores. Other
     * categories are still parsed and reported, just not given a typed
     * accessor.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const KNOWN = [ 'performance', 'accessibility', 'best-practices', 'seo' ];

    /**
     * Convert a lower-kebab response key to the upper-snake request enum.
     *
     * @since 1.0.0
     *
     * @param  string  $responseKey  A response-side category key, e.g. best-practices.
     *
     * @return string The request-side enum value, e.g. BEST_PRACTICES.
     */
    public static function toRequestEnum( string $responseKey ): string
    {
        return strtoupper( str_replace( [ '-', ' ' ], '_', trim( $responseKey ) ) );
    }

    /**
     * Convert an upper-snake request enum to the lower-kebab response key.
     *
     * @since 1.0.0
     *
     * @param  string  $requestEnum  A request-side enum value, e.g. BEST_PRACTICES.
     *
     * @return string The response-side category key, e.g. best-practices.
     */
    public static function toResponseKey( string $requestEnum ): string
    {
        return strtolower( str_replace( [ '_', ' ' ], '-', trim( $requestEnum ) ) );
    }

    /**
     * Whether a response key is one of the four this package scores.
     *
     * @since 1.0.0
     *
     * @param  string  $responseKey  A response-side category key.
     *
     * @return bool True when the category has a typed accessor on the score set.
     */
    public static function isKnown( string $responseKey ): bool
    {
        return in_array( self::toResponseKey( $responseKey ), self::KNOWN, true );
    }
}
