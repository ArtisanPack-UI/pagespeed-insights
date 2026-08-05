<?php

/**
 * Canonicalizes monitored URLs so the same page is not monitored twice.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Urls;

/**
 * Reduces a URL to the form the package stores and compares on.
 *
 * URLs reach this package from four directions — an operator typing one in,
 * a sitemap, another package's hook callback, and the HTTP API — and the
 * same page routinely arrives spelled four different ways. Without a single
 * canonical form, `https://example.com/about`, `https://example.com/about/`,
 * and `HTTPS://Example.com/about#team` become three monitored URLs with
 * three separate score histories for one page.
 *
 * The normalization is deliberately conservative. Only differences that
 * cannot change which document is served are erased: case in the scheme and
 * host, the default port, the fragment, and a trailing slash. Query strings
 * are left alone — `?page=2` is a different page — and `www.` is left alone,
 * because whether the two hosts serve the same document is a fact about a
 * particular site's DNS and redirects, not about URLs.
 *
 * The one exception is embedded credentials, which are dropped for the
 * reason given at the point they are dropped.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class UrlNormalizer
{
    /**
     * The schemes PageSpeed Insights can test.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const ALLOWED_SCHEMES = [ 'http', 'https' ];

    /**
     * The scheme assumed when a URL is written without one.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DEFAULT_SCHEME = 'https';

    /**
     * The ports that are implied by their scheme and so are dropped.
     *
     * @since 1.0.0
     *
     * @var array<string, int>
     */
    public const DEFAULT_PORTS = [
        'http'  => 80,
        'https' => 443,
    ];

    /**
     * The longest URL the package will store.
     *
     * Matches the `url` column, which is 500 characters. A URL longer than
     * the column is rejected here rather than being silently truncated into
     * a row that points somewhere else.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_LENGTH = 500;

    /**
     * Reduce a URL to its canonical form.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL as it was written.
     *
     * @return string|null The canonical URL, or null when it is not a testable http(s) URL.
     */
    public static function normalize( string $url ): ?string
    {
        $trimmed = trim( $url );

        if ( '' === $trimmed ) {
            return null;
        }

        // A bare host or a protocol-relative URL is a common way to write a
        // URL by hand; both are readable as https.
        //
        // Deciding whether a leading `word:` is a scheme takes more than
        // spotting the colon, because a dot is legal in a scheme name and so
        // `example.com:8080/path` parses as the scheme `example.com` — a bare
        // host with a port would be rejected as an unsupported scheme. A
        // colon followed by a digit is therefore read as a port, which keeps
        // `mailto:` and `javascript:` correctly identified as schemes.
        if ( str_starts_with( $trimmed, '//' ) ) {
            $trimmed = self::DEFAULT_SCHEME . ':' . $trimmed;
        } elseif ( ! str_contains( $trimmed, '://' ) && ! preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*:(?![0-9])#', $trimmed ) ) {
            $trimmed = self::DEFAULT_SCHEME . '://' . $trimmed;
        }

        $parts = parse_url( $trimmed );

        if ( false === $parts || ! is_array( $parts ) ) {
            return null;
        }

        $scheme = strtolower( (string) ( $parts[ 'scheme' ] ?? '' ) );
        $host   = strtolower( (string) ( $parts[ 'host' ] ?? '' ) );

        if ( ! in_array( $scheme, self::ALLOWED_SCHEMES, true ) || '' === $host ) {
            return null;
        }

        // Embedded credentials are dropped rather than stored. Keeping them
        // would put a password in plaintext in the `url` column, in the
        // console output of sitemap discovery, and in every admin screen
        // that lists monitored URLs — and buy nothing for it, because
        // PageSpeed fetches the page from Google's own infrastructure, where
        // userinfo in a URL is not honoured. A staging site behind basic auth
        // cannot be tested by this API with or without them.
        $normalized = $scheme . '://' . $host;

        $port = isset( $parts[ 'port' ] ) ? (int) $parts[ 'port' ] : null;

        if ( null !== $port && self::DEFAULT_PORTS[ $scheme ] !== $port ) {
            $normalized .= ':' . $port;
        }

        $normalized .= self::normalizePath( (string) ( $parts[ 'path' ] ?? '' ) );

        if ( isset( $parts[ 'query' ] ) && '' !== $parts[ 'query' ] ) {
            $normalized .= '?' . $parts[ 'query' ];
        }

        // The fragment is never sent to the server, so two URLs that differ
        // only by fragment are the same document to Lighthouse.

        if ( strlen( $normalized ) > self::MAX_LENGTH ) {
            return null;
        }

        return $normalized;
    }

    /**
     * Whether a URL can be monitored.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL as it was written.
     *
     * @return bool True when {@see self::normalize()} would return a URL.
     */
    public static function isValid( string $url ): bool
    {
        return null !== self::normalize( $url );
    }

    /**
     * Normalize the path component.
     *
     * The site root is written as `/` rather than as the empty string, so
     * `https://example.com` and `https://example.com/` agree. Every other
     * path loses its trailing slash, because a server that distinguishes
     * `/about` from `/about/` in the document it serves — rather than
     * redirecting one to the other — is rare enough that treating them as
     * one page is the better default.
     *
     * @since 1.0.0
     *
     * @param  string  $path  The raw path, which may be empty.
     *
     * @return string The normalized path, always beginning with a slash.
     */
    protected static function normalizePath( string $path ): string
    {
        if ( '' === $path ) {
            return '/';
        }

        if ( ! str_starts_with( $path, '/' ) ) {
            $path = '/' . $path;
        }

        $trimmed = rtrim( $path, '/' );

        return '' === $trimmed ? '/' : $trimmed;
    }
}
