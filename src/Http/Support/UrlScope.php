<?php

/**
 * Decides which URLs the HTTP endpoints will answer for.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Http\Support;

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlNormalizer;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The server-side answer to "may this request ask about that URL?".
 *
 * Authentication is not the whole of the question. Every endpoint in this
 * package takes a URL from the query string or the request body, and an
 * authenticated user who can name any URL they like gets two things they
 * should not have:
 *
 * 1. **Read access to somebody else's history.** The results table is keyed on
 *    a plain URL column, so a request naming a third party's address would be
 *    served whatever this installation happens to have stored about it —
 *    including runs an operator queued from the console while diagnosing a
 *    competitor's site.
 * 2. **A way to spend the application's API quota on arbitrary sites.** Each
 *    queued run costs one PageSpeed request against a key the application pays
 *    for and Google rate-limits. An open test endpoint is a free Lighthouse
 *    service with somebody else's quota behind it.
 *
 * So the two halves are scoped differently, and deliberately not by the same
 * rule:
 *
 * - {@see self::allowsRead()} — the monitored set, and nothing else. Reading
 *   is about this installation's own history.
 * - {@see self::allowsTest()} — the monitored set **or** this application's own
 *   origin, because testing a page of your own site that nobody has added to
 *   the monitored list yet is the obvious thing an ad hoc test is for. Widened
 *   to everything by `routes.allow_external_urls`, which is off by default.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class UrlScope
{
    /**
     * Build the scope.
     *
     * @since 1.0.0
     *
     * @param  UrlRegistry  $registry  The monitored set.
     * @param  ConfigRepository  $config  Reads the route and application settings.
     */
    public function __construct(
        protected UrlRegistry $registry,
        protected ConfigRepository $config,
    ) {
    }

    /**
     * Reduce a requested URL to the form the package stores and compares on.
     *
     * @since 1.0.0
     *
     * @param  mixed  $url  The URL as the request wrote it.
     *
     * @return string|null The canonical URL, or null when it is not testable.
     */
    public function normalize( mixed $url ): ?string
    {
        return is_string( $url ) ? UrlNormalizer::normalize( $url ) : null;
    }

    /**
     * Whether a canonical URL is one this installation monitors.
     *
     * Hook-contributed URLs count. They cost quota on every cycle exactly like
     * a stored row does, and a package that registers a page is declaring it
     * part of this installation's monitored set.
     *
     * The stored table is asked first, with an indexed lookup on one URL.
     * `UrlRegistry::find()` would answer the same question by loading the
     * entire monitored set into memory and scanning it, which is the right
     * shape for a management screen listing every row and the wrong one for a
     * check that runs on every single request to every endpoint here.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The canonical URL.
     *
     * @return bool True when the URL is monitored.
     */
    public function isMonitored( string $url ): bool
    {
        if ( null !== $this->registry->findStored( $url ) ) {
            return true;
        }

        // Only reached when the URL is not stored. Assembling the hook side
        // means running every registered callback, so it is worth not doing
        // for the common case.
        return $this->registry->hooked()->contains(
            static fn ( PageSpeedUrl $candidate ): bool => (string) $candidate->url === $url,
        );
    }

    /**
     * Whether the read endpoints may answer for a canonical URL.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The canonical URL.
     *
     * @return bool True when history for this URL may be served.
     */
    public function allowsRead( string $url ): bool
    {
        return $this->isMonitored( $url );
    }

    /**
     * Whether a run may be queued for a canonical URL.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The canonical URL.
     *
     * @return bool True when a test may be queued.
     */
    public function allowsTest( string $url ): bool
    {
        if ( $this->allowsExternalUrls() ) {
            return true;
        }

        return $this->isMonitored( $url ) || $this->isOwnOrigin( $url );
    }

    /**
     * Whether a stored result or ticket may be read back.
     *
     * Deliberately not `allowsTest()`. Queuing a run against an arbitrary URL
     * is what `routes.allow_external_urls` exists to permit; reading whatever
     * this installation happens to have stored about a third party's site is
     * not, and gating a read on the test rule would let that flag turn the
     * results table into something an authenticated user can enumerate by id.
     *
     * An ad hoc run of a page on this application's own origin still has to be
     * readable by whoever queued it, which is why this is wider than
     * `allowsRead()`.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The canonical URL.
     *
     * @return bool True when this run may be read back.
     */
    public function allowsPoll( string $url ): bool
    {
        return $this->isMonitored( $url ) || $this->isOwnOrigin( $url );
    }

    /**
     * Whether `routes.allow_external_urls` is on.
     *
     * @since 1.0.0
     *
     * @return bool True when any URL may be tested.
     */
    public function allowsExternalUrls(): bool
    {
        return true === filter_var(
            $this->config->get( 'pagespeed-insights.routes.allow_external_urls', false ),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * Whether a canonical URL is served by this application.
     *
     * Compared on host and port, ignoring the scheme: an application reached
     * over https and configured with an http `app.url` — or the other way
     * round, which is what a misconfigured proxy produces — is still the same
     * site, and refusing to test its own pages over that is a support ticket
     * rather than a security boundary.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The canonical URL.
     *
     * @return bool True when the URL is on this application's origin.
     */
    public function isOwnOrigin( string $url ): bool
    {
        $appUrl = $this->config->get( 'app.url' );

        if ( ! is_string( $appUrl ) ) {
            return false;
        }

        $normalizedAppUrl = UrlNormalizer::normalize( $appUrl );

        if ( null === $normalizedAppUrl ) {
            return false;
        }

        $authority = self::authority( $url );

        return '' !== $authority && $authority === self::authority( $normalizedAppUrl );
    }

    /**
     * The host and port of a URL, lowercased.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL to read.
     *
     * @return string The authority, or an empty string when there is none.
     */
    protected static function authority( string $url ): string
    {
        $parts = parse_url( $url );

        if ( ! is_array( $parts ) || ! isset( $parts[ 'host' ] ) ) {
            return '';
        }

        $authority = strtolower( (string) $parts[ 'host' ] );

        if ( isset( $parts[ 'port' ] ) ) {
            $authority .= ':' . (int) $parts[ 'port' ];
        }

        return $authority;
    }
}
