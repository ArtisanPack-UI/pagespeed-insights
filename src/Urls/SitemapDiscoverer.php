<?php

/**
 * Finds monitorable URLs by reading a site's sitemap.
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

use ArtisanPackUI\PageSpeedInsights\Exceptions\SitemapException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use LibXMLError;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use Throwable;

/**
 * Walks `sitemap.xml`, following sitemap index files, and returns the page
 * URLs it lists.
 *
 * Sitemaps in the wild are not the sitemaps in the specification. They are
 * served as HTML error pages, truncated mid-document by a timed-out
 * generator, wrapped in stylesheet processing instructions, namespaced three
 * different ways, and nested several index files deep. So parsing here is
 * tolerant by design: a document that libxml can only partly read still
 * yields the URLs it did manage to read, and a child sitemap that fails is
 * skipped rather than aborting its siblings.
 *
 * The tolerance stops at the entry point. If the sitemap the caller actually
 * named cannot be fetched or parsed at all, that is a
 * {@see SitemapException} — telling an operator "0 URLs found" when the
 * address 404s is the kind of quiet failure that costs an afternoon.
 *
 * ### Bounds
 *
 * A sitemap is an untrusted document whose whole purpose is to name more
 * documents to fetch, so a run is bounded six ways: a cap on discovered URLs,
 * a cap on recursion depth, a cap on how many sitemap documents are fetched, a
 * cap on how many bytes one of them may occupy, a requirement that a child
 * sitemap share the whole origin of the index that named it, and — unless
 * {@see self::allowExternal()} says otherwise — a requirement that a page
 * entry live on the sitemap's own host. A sitemap index that points at itself
 * terminates on the first of those to trip; one that points at a loopback
 * service or a cloud metadata endpoint never gets fetched at all.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class SitemapDiscoverer
{
    /**
     * How many URLs a run keeps when nothing else is configured.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_LIMIT = 50;

    /**
     * Seconds to wait for one sitemap document.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_TIMEOUT = 15;

    /**
     * How many levels of sitemap index to follow. One index pointing at
     * per-section indexes pointing at urlsets is the deepest real structure
     * this has to handle.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_DEPTH = 3;

    /**
     * How many sitemap documents one run will fetch, whatever the depth.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_DOCUMENTS = 50;

    /**
     * The path appended to the app URL when no sitemap is configured.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DEFAULT_PATH = '/sitemap.xml';

    /**
     * The root elements a sitemap document is allowed to have.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const ROOT_ELEMENTS = [ 'urlset', 'sitemapindex' ];

    /**
     * The most bytes one sitemap document may occupy.
     *
     * The sitemaps.org protocol caps an uncompressed document at 50 MB, and a
     * document larger than this cap is not a sitemap this package can use
     * regardless — while a compressed body that inflates past it is a way to
     * exhaust a worker's memory from outside.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_BYTES = 10485760;

    /**
     * libxml's `XML_PARSE_RECOVER` parse option.
     *
     * Written as its value rather than as `LIBXML_RECOVER` because PHP only
     * exposes that constant from 8.4 onwards and this package supports 8.2.
     * The underlying flag is part of libxml2's public enum and has been 1
     * since 2.6, so the value is as stable as the constant would be.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const RECOVER = 1;

    /**
     * Sitemap documents already fetched during the current run.
     *
     * @since 1.0.0
     *
     * @var array<string, true>
     */
    protected array $visited = [];

    /**
     * Whether page entries on a host other than the sitemap's own are kept.
     *
     * Off by default. Whoever controls sitemap content would otherwise decide
     * what this installation monitors, and a monitored URL is one every
     * authenticated user may read history for and queue paid runs against.
     * The agency case — one operator deliberately pointing the discoverer at a
     * client's sitemap — turns it on explicitly.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected bool $allowExternal = false;

    /**
     * Build the discoverer.
     *
     * @since 1.0.0
     *
     * @param  HttpFactory  $http  The HTTP client factory.
     * @param  ConfigRepository  $config  The application config repository.
     * @param  LoggerInterface  $logger  Where skipped sitemaps are reported.
     */
    public function __construct(
        protected HttpFactory $http,
        protected ConfigRepository $config,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Read a sitemap and return the page URLs it lists.
     *
     * @since 1.0.0
     *
     * @param  string|null  $sitemap  The sitemap URL; defaults to the configured one.
     * @param  int|null  $limit  The most URLs to return; defaults to the configured cap.
     *
     * @throws SitemapException When the named sitemap cannot be fetched or parsed.
     *
     * @return array<int, string> Normalized page URLs, in the order the sitemap listed them.
     */
    public function discover( ?string $sitemap = null, ?int $limit = null ): array
    {
        $target = UrlNormalizer::normalize( $sitemap ?? $this->defaultSitemapUrl() );

        if ( null === $target ) {
            throw SitemapException::invalidUrl( (string) ( $sitemap ?? $this->defaultSitemapUrl() ) );
        }

        $this->visited = [];

        $found = $this->walk( $target, $this->resolveLimit( $limit ), 0, true );

        return array_values( $found );
    }

    /**
     * Keep or drop page entries hosted somewhere other than the sitemap.
     *
     * @since 1.0.0
     *
     * @param  bool  $allow  True to keep third-party page entries.
     *
     * @return self This discoverer, for chaining.
     */
    public function allowExternal( bool $allow = true ): self
    {
        $this->allowExternal = $allow;

        return $this;
    }

    /**
     * The sitemap used when the caller names none.
     *
     * @since 1.0.0
     *
     * @return string The configured sitemap URL, or `sitemap.xml` at the app URL.
     */
    public function defaultSitemapUrl(): string
    {
        $configured = $this->config->get( 'pagespeed-insights.sitemap.url' );

        if ( is_string( $configured ) && '' !== trim( $configured ) ) {
            return trim( $configured );
        }

        $appUrl = $this->config->get( 'app.url' );
        $appUrl = is_string( $appUrl ) && '' !== trim( $appUrl ) ? trim( $appUrl ) : 'http://localhost';

        return rtrim( $appUrl, '/' ) . self::DEFAULT_PATH;
    }

    /**
     * Fetch one sitemap and everything it points at.
     *
     * @since 1.0.0
     *
     * @param  string  $sitemap  The sitemap URL to read.
     * @param  int  $limit  The most URLs to collect in total.
     * @param  int  $depth  How many index files deep this call is.
     * @param  bool  $required  Whether a failure here should throw rather than be skipped.
     *
     * @throws SitemapException When a required sitemap cannot be read.
     *
     * @return array<string, string> Normalized URLs keyed by themselves, so nesting dedupes for free.
     */
    protected function walk( string $sitemap, int $limit, int $depth, bool $required = false ): array
    {
        if ( isset( $this->visited[ $sitemap ] ) || count( $this->visited ) >= self::MAX_DOCUMENTS ) {
            return [];
        }

        $this->visited[ $sitemap ] = true;

        try {
            $document = $this->fetch( $sitemap, $required );
            $xml      = $this->parse( $sitemap, $document[ 'body' ] );
        } catch ( SitemapException $exception ) {
            if ( $required ) {
                throw $exception;
            }

            $this->logger->warning(
                'Skipped a nested sitemap that could not be read: ' . $exception->getMessage(),
                [ 'sitemap' => $sitemap ],
            );

            return [];
        }

        if ( 'sitemapindex' === $xml->getName() ) {
            return $this->walkIndex( $xml, $document[ 'url' ], $limit, $depth );
        }

        // Measured against the address the document was actually served from
        // rather than the one that was asked for. The sitemap the caller named
        // follows redirects on purpose, and apex-to-www is the ordinary case —
        // judging its entries against the pre-redirect host would drop every
        // one of them.
        $host  = parse_url( $document[ 'url' ], PHP_URL_HOST );
        $found = [];

        foreach ( $this->locations( $xml, 'url' ) as $location ) {
            if ( count( $found ) >= $limit ) {
                break;
            }

            $normalized = UrlNormalizer::normalize( $location );

            if ( null === $normalized ) {
                continue;
            }

            // Whoever writes the sitemap would otherwise choose what this
            // installation monitors — and a monitored URL is one every
            // authenticated user can read history for, plus recurring quota
            // against the operator's own key.
            if ( ! $this->allowExternal && parse_url( $normalized, PHP_URL_HOST ) !== $host ) {
                $this->logger->warning(
                    'Skipped a sitemap entry on a different host than the sitemap that listed it.',
                    [ 'sitemap' => $document[ 'url' ], 'url' => $normalized ],
                );

                continue;
            }

            $found[ $normalized ] = $normalized;
        }

        return $found;
    }

    /**
     * Follow every sitemap named by an index document.
     *
     * A child sitemap on a different origin than its index is skipped —
     * compared on scheme, host, and port, not host alone. The sitemaps.org
     * protocol already forbids a different host without a cross-submit
     * verification this package has no way to perform, and allowing it would
     * turn any sitemap this application is pointed at into a list of
     * addresses the application will fetch on the author's behalf — a
     * request-forgery primitive reaching link-local metadata endpoints and
     * loopback services that no firewall between the app and the internet
     * would see. Scheme and port are part of that comparison because a
     * host-only rule still reaches a service listening on another port of the
     * same machine, and still permits a silent https-to-http downgrade.
     *
     * @since 1.0.0
     *
     * @param  SimpleXMLElement  $xml  The parsed `sitemapindex`.
     * @param  string  $parent  The URL the index was fetched from.
     * @param  int  $limit  The most URLs to collect in total.
     * @param  int  $depth  How many index files deep this call is.
     *
     * @return array<string, string> Normalized URLs keyed by themselves.
     */
    protected function walkIndex( SimpleXMLElement $xml, string $parent, int $limit, int $depth ): array
    {
        if ( $depth >= self::MAX_DEPTH ) {
            $this->logger->warning(
                'Stopped following sitemap index files at the maximum depth of ' . self::MAX_DEPTH . '.',
            );

            return [];
        }

        $parentParts = parse_url( $parent );
        $parentParts = is_array( $parentParts ) ? $parentParts : [];
        $found       = [];

        foreach ( $this->locations( $xml, 'sitemap' ) as $location ) {
            if ( count( $found ) >= $limit ) {
                break;
            }

            $child = UrlNormalizer::normalize( $location );

            if ( null === $child ) {
                continue;
            }

            $childParts = parse_url( $child );
            $childParts = is_array( $childParts ) ? $childParts : [];

            // Compared on the whole origin rather than on the host alone. A
            // host-only rule lets an index send the discoverer to
            // `http://example.com:9200/` — a request primitive against
            // services that firewall the internet but trust their own host,
            // and a silent https-to-http downgrade on the way. Ports are
            // already canonicalized by `UrlNormalizer`, so a strict comparison
            // does not trip over the default port being written out.
            $sameOrigin = ( $childParts[ 'scheme' ] ?? '' ) === ( $parentParts[ 'scheme' ] ?? '' )
                && strtolower( (string) ( $childParts[ 'host' ] ?? '' ) ) === strtolower( (string) ( $parentParts[ 'host' ] ?? '' ) )
                && ( $childParts[ 'port' ] ?? null ) === ( $parentParts[ 'port' ] ?? null );

            if ( ! $sameOrigin ) {
                $this->logger->warning(
                    'Skipped a sitemap named by an index on a different origin.',
                    [ 'index' => $parent, 'sitemap' => $child ],
                );

                continue;
            }

            $found += $this->walk( $child, $limit - count( $found ), $depth + 1 );
        }

        return array_slice( $found, 0, $limit, true );
    }

    /**
     * Read the `<loc>` values out of a sitemap document.
     *
     * Reads them by local name so that the sitemaps.org namespace, a
     * generator's own namespace, and no namespace at all are all handled the
     * same way — the alternative is a parser that silently returns nothing
     * for a perfectly valid document written with a prefix.
     *
     * @since 1.0.0
     *
     * @param  SimpleXMLElement  $xml  The parsed document.
     * @param  string  $entry  The entry element to read: `url` or `sitemap`.
     *
     * @return array<int, string> The raw location strings.
     */
    protected function locations( SimpleXMLElement $xml, string $entry ): array
    {
        $nodes = $xml->xpath(
            '//*[local-name()="' . $entry . '"]/*[local-name()="loc"]',
        );

        if ( ! is_array( $nodes ) || [] === $nodes ) {
            return [];
        }

        $locations = [];

        foreach ( $nodes as $node ) {
            $location = trim( (string) $node );

            if ( '' !== $location ) {
                $locations[] = $location;
            }
        }

        return $locations;
    }

    /**
     * Fetch a sitemap document.
     *
     * Redirects are followed only for the sitemap the caller named, and never
     * for one a sitemap index pointed at. The same-host rule in
     * {@see self::walkIndex()} is applied to the address before the request,
     * so a child that is allowed through and then answers `302
     * http://169.254.169.254/` would defeat it — the check has to hold for
     * the address actually fetched, and the cheapest way to guarantee that is
     * to make the fetched address the only one there is.
     *
     * The named sitemap keeps following redirects because that address is
     * the operator's own choice rather than something a document supplied,
     * and http-to-https and apex-to-www redirects are ordinary enough that
     * refusing them would break the common case for no gain: anyone able to
     * name the sitemap could name the redirect target directly.
     *
     * @since 1.0.0
     *
     * The effective address is reported back alongside the body because the
     * host a document's entries are judged against has to be the host that
     * served it, not the one that was asked for — otherwise following an
     * apex-to-www redirect would make every entry in the document look like
     * somebody else's.
     * @since 1.0.0
     *
     * @param  string  $sitemap  The sitemap URL.
     * @param  bool  $followRedirects  Whether this address came from the caller rather than from a document.
     *
     * @throws SitemapException When the request fails, the response is not a success, or the body is too large.
     *
     * @return array{body: string, url: string} The response body and the address it came from.
     */
    protected function fetch( string $sitemap, bool $followRedirects = false ): array
    {
        try {
            $request = $this->http
                ->timeout( $this->resolveTimeout() )
                ->withHeaders( [ 'Accept' => 'application/xml, text/xml;q=0.9, */*;q=0.8' ] );

            if ( ! $followRedirects ) {
                $request->withoutRedirecting();
            }

            $response = $request->get( $sitemap );
        } catch ( Throwable $exception ) {
            throw SitemapException::transportFailure( $sitemap, $exception );
        }

        if ( ! $response->successful() ) {
            throw SitemapException::unreachable( $sitemap, $response->status() );
        }

        // Checked before the body is touched where the server declared a
        // length, and again afterwards because a chunked response declares
        // none and Guzzle transparently inflates `Content-Encoding: gzip` — so
        // a small compressed body can arrive as hundreds of megabytes.
        if ( (int) $response->header( 'Content-Length' ) > self::MAX_BYTES ) {
            throw SitemapException::tooLarge( $sitemap, self::MAX_BYTES );
        }

        $body = (string) $response->body();

        if ( strlen( $body ) > self::MAX_BYTES ) {
            throw SitemapException::tooLarge( $sitemap, self::MAX_BYTES );
        }

        $effective = $response->effectiveUri();

        return [
            'body' => $body,
            'url'  => null === $effective ? $sitemap : (string) $effective,
        ];
    }

    /**
     * Parse a sitemap body, keeping whatever libxml could read.
     *
     * @since 1.0.0
     *
     * @param  string  $sitemap  The sitemap URL, for the error message.
     * @param  string  $body  The response body.
     *
     * @throws SitemapException When nothing usable could be parsed.
     *
     * @return SimpleXMLElement The parsed document.
     */
    protected function parse( string $sitemap, string $body ): SimpleXMLElement
    {
        $body = trim( $body );

        if ( '' === $body ) {
            throw SitemapException::unreadable( $sitemap );
        }

        $previous = libxml_use_internal_errors( true );
        libxml_clear_errors();

        // LIBXML_NONET blocks the parser from fetching anything the document
        // names; entity substitution is left off so an untrusted sitemap
        // cannot turn into a file read. self::RECOVER is what makes a
        // truncated document still yield the entries above the truncation.
        $xml = simplexml_load_string( $body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | self::RECOVER );

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $xml instanceof SimpleXMLElement ) {
            throw SitemapException::unreadable( $sitemap );
        }

        // Recovering a body that is not XML at all — a bare `Not found`, say
        // — yields a SimpleXMLElement that wraps no node. It satisfies
        // instanceof, and then every method on it throws an Error rather
        // than returning something falsy, so reading the name is the only
        // way to tell it apart from a real document.
        try {
            $root = $xml->getName();
        } catch ( Throwable ) {
            throw SitemapException::unreadable( $sitemap );
        }

        // Recovery mode is why this check is necessary rather than paranoid:
        // an HTML error page parses cleanly into a document rooted at `html`,
        // so without it a custom 404 served with a 200 status would report
        // zero URLs found instead of saying the address is wrong.
        if ( ! in_array( $root, self::ROOT_ELEMENTS, true ) ) {
            throw SitemapException::notASitemap( $sitemap, $root );
        }

        if ( [] !== $errors ) {
            $this->logger->info(
                'Recovered a partly malformed sitemap; the entries that parsed were kept.',
                [
                    'sitemap' => $sitemap,
                    'errors'  => array_slice(
                        array_map(
                            static fn ( LibXMLError $error ): string => trim( $error->message ),
                            $errors,
                        ),
                        0,
                        5,
                    ),
                ],
            );
        }

        return $xml;
    }

    /**
     * The cap on discovered URLs for this run.
     *
     * @since 1.0.0
     *
     * @param  int|null  $limit  The caller's cap, when they set one.
     *
     * @return int A positive cap.
     */
    protected function resolveLimit( ?int $limit ): int
    {
        if ( null !== $limit && $limit > 0 ) {
            return $limit;
        }

        $configured = $this->config->get( 'pagespeed-insights.sitemap.limit', self::DEFAULT_LIMIT );

        if ( ! is_numeric( $configured ) || (int) $configured <= 0 ) {
            return self::DEFAULT_LIMIT;
        }

        return (int) $configured;
    }

    /**
     * Seconds to wait for one sitemap document.
     *
     * @since 1.0.0
     *
     * @return int A positive timeout.
     */
    protected function resolveTimeout(): int
    {
        $configured = $this->config->get( 'pagespeed-insights.sitemap.timeout', self::DEFAULT_TIMEOUT );

        if ( ! is_numeric( $configured ) || (int) $configured <= 0 ) {
            return self::DEFAULT_TIMEOUT;
        }

        return (int) $configured;
    }
}
