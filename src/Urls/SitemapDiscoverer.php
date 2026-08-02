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
 * documents to fetch, so a run is bounded four ways: a cap on discovered
 * URLs, a cap on recursion depth, a cap on how many sitemap documents are
 * fetched, and a requirement that a child sitemap live on the same host as
 * the index that named it. A sitemap index that points at itself terminates
 * on the first of those to trip; one that points at a loopback service or a
 * cloud metadata endpoint never gets fetched at all.
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
     * Sitemap documents already fetched during the current run.
     *
     * @since 1.0.0
     *
     * @var array<string, true>
     */
    protected array $visited = [];

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
            $xml = $this->parse( $sitemap, $this->fetch( $sitemap ) );
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
            return $this->walkIndex( $xml, $sitemap, $limit, $depth );
        }

        $found = [];

        foreach ( $this->locations( $xml, 'url' ) as $location ) {
            if ( count( $found ) >= $limit ) {
                break;
            }

            $normalized = UrlNormalizer::normalize( $location );

            if ( null === $normalized ) {
                continue;
            }

            $found[ $normalized ] = $normalized;
        }

        return $found;
    }

    /**
     * Follow every sitemap named by an index document.
     *
     * @since 1.0.0
     *
     * A child sitemap on a different host than its index is skipped. The
     * sitemaps.org protocol already forbids it without a cross-submit
     * verification this package has no way to perform, and allowing it would
     * turn any sitemap this application is pointed at into a list of
     * addresses the application will fetch on the author's behalf — a
     * request-forgery primitive reaching link-local metadata endpoints and
     * loopback services that no firewall between the app and the internet
     * would see.
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

        $host  = (string) parse_url( $parent, PHP_URL_HOST );
        $found = [];

        foreach ( $this->locations( $xml, 'sitemap' ) as $location ) {
            if ( count( $found ) >= $limit ) {
                break;
            }

            $child = UrlNormalizer::normalize( $location );

            if ( null === $child ) {
                continue;
            }

            if ( parse_url( $child, PHP_URL_HOST ) !== $host ) {
                $this->logger->warning(
                    'Skipped a sitemap named by an index on a different host.',
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
     * @since 1.0.0
     *
     * @param  string  $sitemap  The sitemap URL.
     *
     * @throws SitemapException When the request fails or the response is not a success.
     *
     * @return string The response body.
     */
    protected function fetch( string $sitemap ): string
    {
        try {
            $response = $this->http
                ->timeout( $this->resolveTimeout() )
                ->withHeaders( [ 'Accept' => 'application/xml, text/xml;q=0.9, */*;q=0.8' ] )
                ->get( $sitemap );
        } catch ( Throwable $exception ) {
            throw SitemapException::transportFailure( $sitemap, $exception );
        }

        if ( ! $response->successful() ) {
            throw SitemapException::unreachable( $sitemap, $response->status() );
        }

        return (string) $response->body();
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
        // cannot turn into a file read. RECOVER is what makes a truncated
        // document still yield the entries above the truncation.
        $xml = simplexml_load_string( $body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_RECOVER );

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $xml instanceof SimpleXMLElement ) {
            throw SitemapException::unreadable( $sitemap );
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
