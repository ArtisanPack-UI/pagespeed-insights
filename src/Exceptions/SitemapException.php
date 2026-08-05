<?php

/**
 * Sitemap discovery exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The sitemap a discovery run was pointed at could not be read.
 *
 * Thrown only for the sitemap the caller named. A nested sitemap that 404s
 * or contains garbage is logged and skipped instead, because one broken
 * child in a sitemap index should not throw away the hundreds of URLs its
 * siblings listed.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class SitemapException extends RuntimeException
{
    /**
     * The sitemap URL that could not be read.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $sitemap = null;

    /**
     * The sitemap address is not a usable http(s) URL.
     *
     * @since 1.0.0
     *
     * @param  string  $sitemap  The address as it was supplied.
     *
     * @return static The built exception.
     */
    public static function invalidUrl( string $sitemap ): static
    {
        return static::for(
            $sitemap,
            __(
                ':sitemap is not a valid http or https URL, so it cannot be fetched as a sitemap.',
                [ 'sitemap' => $sitemap ],
            ),
        );
    }

    /**
     * The sitemap could not be fetched.
     *
     * @since 1.0.0
     *
     * @param  string  $sitemap  The sitemap URL.
     * @param  int  $status  The HTTP status returned.
     *
     * @return static The built exception.
     */
    public static function unreachable( string $sitemap, int $status ): static
    {
        return static::for(
            $sitemap,
            __(
                'Fetching :sitemap returned HTTP :status. Check that the site publishes a sitemap at that address.',
                [ 'sitemap' => $sitemap, 'status' => (string) $status ],
            ),
        );
    }

    /**
     * The request never completed.
     *
     * @since 1.0.0
     *
     * @param  string  $sitemap  The sitemap URL.
     * @param  Throwable  $previous  The underlying transport exception.
     *
     * @return static The built exception.
     */
    public static function transportFailure( string $sitemap, Throwable $previous ): static
    {
        return static::for(
            $sitemap,
            __(
                'Could not reach :sitemap. Transport error: :message',
                [ 'sitemap' => $sitemap, 'message' => $previous->getMessage() ],
            ),
            $previous,
        );
    }

    /**
     * The response is larger than one sitemap document may be.
     *
     * @since 1.0.0
     *
     * @param  string  $sitemap  The sitemap URL.
     * @param  int  $maxBytes  The cap that was exceeded.
     *
     * @return static The built exception.
     */
    public static function tooLarge( string $sitemap, int $maxBytes ): static
    {
        return static::for(
            $sitemap,
            __(
                'The response from :sitemap is larger than the :max byte limit this package reads. A document that big is not a sitemap this package can use, and reading it risks exhausting the memory of the process that asked for it.',
                [ 'sitemap' => $sitemap, 'max' => (string) $maxBytes ],
            ),
        );
    }

    /**
     * The response was fetched but is not readable as a sitemap.
     *
     * @since 1.0.0
     *
     * @param  string  $sitemap  The sitemap URL.
     *
     * @return static The built exception.
     */
    public static function unreadable( string $sitemap ): static
    {
        return static::for(
            $sitemap,
            __(
                'The response from :sitemap could not be parsed as XML. It may be an HTML error page rather than a sitemap.',
                [ 'sitemap' => $sitemap ],
            ),
        );
    }

    /**
     * The response parsed as XML but is not a sitemap.
     *
     * Worth separating from {@see self::unreadable()} because recovery-mode
     * parsing succeeds on an HTML error page — it yields a document rooted
     * at `html` — and a custom 404 page served with HTTP 200 is common
     * enough that "0 URLs found" would otherwise be the only symptom.
     *
     * @since 1.0.0
     *
     * @param  string  $sitemap  The sitemap URL.
     * @param  string  $root  The root element that was found instead.
     *
     * @return static The built exception.
     */
    public static function notASitemap( string $sitemap, string $root ): static
    {
        return static::for(
            $sitemap,
            __(
                'The response from :sitemap is rooted at <:root> rather than <urlset> or <sitemapindex>, so it is not a sitemap. A page served at that address with a 200 status — a custom error page, for instance — looks like this.',
                [ 'sitemap' => $sitemap, 'root' => $root ],
            ),
        );
    }

    /**
     * Build an exception that remembers which sitemap it is about.
     *
     * @since 1.0.0
     *
     * @param  string  $sitemap  The sitemap URL.
     * @param  string  $message  The human-readable message.
     * @param  Throwable|null  $previous  The underlying exception, when there was one.
     *
     * @return static The built exception.
     */
    protected static function for( string $sitemap, string $message, ?Throwable $previous = null ): static
    {
        $exception          = new static( $message, 0, $previous );
        $exception->sitemap = $sitemap;

        return $exception;
    }
}
