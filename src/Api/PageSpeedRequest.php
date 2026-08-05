<?php

/**
 * PageSpeed Insights request value object.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Api;

use ArtisanPackUI\PageSpeedInsights\Support\CategoryTranslator;
use InvalidArgumentException;

/**
 * The parameters of a single runPagespeed call.
 *
 * Categories are held in the response-key spelling (lower-kebab) because that
 * is what the rest of the package keys off; {@see self::toQuery()} translates
 * them to the request enum on the way out.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class PageSpeedRequest
{
    /**
     * Mobile form factor.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STRATEGY_MOBILE = 'mobile';

    /**
     * Desktop form factor.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STRATEGY_DESKTOP = 'desktop';

    /**
     * The URL to test.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public readonly string $url;

    /**
     * The form factor, lower-cased: mobile or desktop.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public readonly string $strategy;

    /**
     * Requested categories in response-key spelling, deduplicated.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public readonly array $categories;

    /**
     * BCP-47 locale for the returned strings, or null for the API default.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public readonly ?string $locale;

    /**
     * Build the request.
     *
     * @since 1.0.0
     *
     * @param  string             $url         Absolute http(s) URL to test.
     * @param  string             $strategy    mobile or desktop; case-insensitive.
     * @param  array<int, string> $categories  Response-key category spellings.
     * @param  string|null        $locale      Optional BCP-47 locale.
     *
     * @throws InvalidArgumentException When the URL or strategy is unusable.
     */
    public function __construct(
        string $url,
        string $strategy = self::STRATEGY_MOBILE,
        array $categories = CategoryTranslator::KNOWN,
        ?string $locale = null,
    ) {
        $url = trim( $url );

        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $url ) ) {
            throw new InvalidArgumentException(
                __( 'PageSpeed Insights needs an absolute http or https URL; got ":url".', [ 'url' => $url ] ),
            );
        }

        $normalizedStrategy = strtolower( trim( $strategy ) );

        if ( ! in_array( $normalizedStrategy, [ self::STRATEGY_MOBILE, self::STRATEGY_DESKTOP ], true ) ) {
            throw new InvalidArgumentException(
                __( 'PageSpeed strategy must be "mobile" or "desktop"; got ":strategy".', [ 'strategy' => $strategy ] ),
            );
        }

        $normalizedCategories = [];

        foreach ( $categories as $category ) {
            if ( ! is_string( $category ) ) {
                continue;
            }

            $key = CategoryTranslator::toResponseKey( $category );

            if ( '' !== $key && ! in_array( $key, $normalizedCategories, true ) ) {
                $normalizedCategories[] = $key;
            }
        }

        if ( [] === $normalizedCategories ) {
            $normalizedCategories = CategoryTranslator::KNOWN;
        }

        $normalizedLocale = null === $locale ? null : trim( $locale );

        $this->url        = $url;
        $this->strategy   = $normalizedStrategy;
        $this->categories = $normalizedCategories;
        $this->locale     = '' === $normalizedLocale ? null : $normalizedLocale;
    }

    /**
     * The query parameters for runPagespeed, minus credentials.
     *
     * `category` is repeatable and is emitted once per requested category —
     * omitting it entirely would return performance only. `strategy` is
     * always sent because the API defaults to desktop.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Query parameters ready for the HTTP client.
     */
    public function toQuery(): array
    {
        $query = [
            'url'      => $this->url,
            'strategy' => strtoupper( $this->strategy ),
            'category' => array_map(
                static fn ( string $key ): string => CategoryTranslator::toRequestEnum( $key ),
                $this->categories,
            ),
        ];

        if ( null !== $this->locale ) {
            $query[ 'locale' ] = $this->locale;
        }

        return $query;
    }

    /**
     * Structured context for log lines about this request.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Log context.
     */
    public function logContext(): array
    {
        return [
            'url'      => $this->url,
            'strategy' => $this->strategy,
        ];
    }
}
