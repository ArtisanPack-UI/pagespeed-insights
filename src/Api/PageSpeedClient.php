<?php

/**
 * PageSpeed Insights API client.
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

use ArtisanPackUI\Google\Tokens\TokenManager;
use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;
use ArtisanPackUI\PageSpeedInsights\Data\TestResult;
use ArtisanPackUI\PageSpeedInsights\Exceptions\MissingApiKeyException;
use ArtisanPackUI\PageSpeedInsights\Exceptions\PageSpeedApiException;
use ArtisanPackUI\PageSpeedInsights\Exceptions\QuotaExceededException;
use ArtisanPackUI\PageSpeedInsights\Support\CategoryTranslator;
use ArtisanPackUI\PageSpeedInsights\Support\GoogleConnectionResolver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs a single `runPagespeed` call and hands the payload to the parser.
 *
 * ### Credential resolution
 *
 * 1. An API key from the configured {@see ApiKeyRepository}, sent on the
 *    {@see self::API_KEY_HEADER} header rather than the documented `key=`
 *    query parameter, so it stays out of URLs. This is the only reliably
 *    working credential.
 * 2. An OAuth bearer token from a connected `artisanpack-ui/google` account,
 *    when no API key is configured. Kept because the plan requires the base
 *    package, but treated as a long shot rather than a supported mode.
 * 3. Nothing — which throws {@see MissingApiKeyException} *before* sending,
 *    rather than spending 20-60 seconds on a request that is guaranteed to
 *    fail. Google's shared anonymous project has a daily quota of zero, so
 *    there is no working keyless mode to degrade to.
 *
 * That last point is also why a 429 is not classified on status alone: an
 * unkeyed request and an exhausted project return the same code for
 * completely different reasons, and reporting the first as quota exhaustion
 * is what makes a never-configured install look like a broken queue.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class PageSpeedClient
{
    /**
     * Fallback endpoint when config is missing or blank.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DEFAULT_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /**
     * Fallback request timeout, in seconds. Google's own runs take 20-60
     * seconds, so this is generous by ordinary HTTP standards.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_TIMEOUT = 90;

    /**
     * Fallback number of opportunities to keep from each run.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_OPPORTUNITIES_LIMIT = 10;

    /**
     * The header Google APIs accept an API key on.
     *
     * Preferred over the documented `key=` query parameter so the key never
     * appears in a URL. A URL-borne key leaks into places that are hard to
     * take back: Guzzle appends the full request URI to its connection-error
     * messages, and those land in exception reports and logs verbatim.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const API_KEY_HEADER = 'X-goog-api-key';

    /**
     * Build the client.
     *
     * @since 1.0.0
     *
     * @param  HttpFactory  $http  The HTTP client factory.
     * @param  ConfigRepository  $config  The application config repository.
     * @param  ApiKeyRepository  $apiKeys  The configured API key storage driver.
     * @param  GoogleConnectionResolver  $connections  Finds a Google account for the OAuth fallback.
     * @param  LoggerInterface  $logger  Where credential and parsing diagnostics go.
     * @param  TokenManager|null  $tokens  The base package's token manager, when available.
     */
    public function __construct(
        protected HttpFactory $http,
        protected ConfigRepository $config,
        protected ApiKeyRepository $apiKeys,
        protected GoogleConnectionResolver $connections,
        protected LoggerInterface $logger,
        protected ?TokenManager $tokens = null,
    ) {
    }

    /**
     * Test a URL with the configured categories.
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
        return $this->run( new PageSpeedRequest( $url, $strategy, $this->configuredCategories(), $locale ) );
    }

    /**
     * Run a prepared request.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedRequest  $request  The request to run.
     *
     * @throws MissingApiKeyException When no credential is available.
     * @throws QuotaExceededException When a keyed project is out of quota.
     * @throws PageSpeedApiException When the transport, the API, or Lighthouse failed.
     *
     * @return TestResult The parsed result.
     */
    public function run( PageSpeedRequest $request ): TestResult
    {
        $query   = $request->toQuery();
        $apiKey  = $this->apiKey();
        $headers = [];

        if ( null !== $apiKey ) {
            $headers[ self::API_KEY_HEADER ] = $apiKey;
        } else {
            $token = $this->oauthToken();

            if ( null === $token ) {
                throw MissingApiKeyException::notConfigured( $request->url );
            }

            $headers[ 'Authorization' ] = 'Bearer ' . $token;

            $this->logger->warning(
                'Running PageSpeed with an OAuth token because no API key is configured. Google attributes quota to the API key, so this is likely to fail; set PAGESPEED_API_KEY.',
                $request->logContext(),
            );
        }

        $response = $this->send( $request, $query, $headers );

        if ( ! $response->successful() ) {
            throw $this->classifyFailure( $request, $response, null !== $apiKey );
        }

        $payload = $response->json();

        if ( ! is_array( $payload ) ) {
            throw PageSpeedApiException::malformedResponse( $request->url );
        }

        return ( new PageSpeedResponse(
            $payload,
            $request,
            $this->logger,
            $this->opportunitiesLimit(),
        ) )->toTestResult();
    }

    /**
     * Issue the HTTP request.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedRequest  $request  The request being run.
     * @param  array<string, mixed>  $query  Query parameters, including credentials.
     * @param  array<string, string>  $headers  Additional headers.
     *
     * @throws PageSpeedApiException When the network never reached Google.
     *
     * @return Response The HTTP response.
     */
    protected function send( PageSpeedRequest $request, array $query, array $headers ): Response
    {
        $url = $this->endpoint() . '?' . $this->buildQueryString( $query );

        try {
            return $this->http
                ->timeout( $this->timeout() )
                ->withHeaders( $headers )
                ->acceptJson()
                ->get( $url );
        } catch ( ConnectionException $e ) {
            throw PageSpeedApiException::transportFailure( $request->url, $e );
        }
    }

    /**
     * Turn a failed response into the exception that names its actual cause.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedRequest  $request  The request being run.
     * @param  Response  $response  The failed response.
     * @param  bool  $usedApiKey  Whether the request carried an API key.
     *
     * @return PageSpeedApiException The exception to throw.
     */
    protected function classifyFailure( PageSpeedRequest $request, Response $response, bool $usedApiKey ): PageSpeedApiException
    {
        $json   = $response->json();
        $body   = is_array( $json ) ? $json : [];
        $error  = is_array( $body[ 'error' ] ?? null ) ? $body[ 'error' ] : [];
        $detail = is_string( $error[ 'message' ] ?? null ) ? $error[ 'message' ] : null;

        if ( 429 !== $response->status() ) {
            $this->logger->error(
                'The PageSpeed Insights API returned an error.',
                $request->logContext() + [ 'status' => $response->status(), 'detail' => $detail ],
            );

            return PageSpeedApiException::apiError( $response->status(), $request->url, $detail );
        }

        // Keyless PageSpeed returns 429 too, because the shared anonymous
        // project's daily limit is zero. Same status, different cause, and
        // completely different fix.
        if ( ! $usedApiKey ) {
            $this->logger->error(
                'PageSpeed returned a quota error for a request with no API key. This is a configuration problem, not quota exhaustion.',
                $request->logContext() + [ 'detail' => $detail ],
            );

            return MissingApiKeyException::quotaBlockedWithoutKey( $request->url );
        }

        [ $metric, $limit ] = $this->quotaNames( $error );

        $this->logger->error(
            'The PageSpeed Insights quota for the configured API key is exhausted.',
            $request->logContext() + [ 'quota_metric' => $metric, 'quota_limit' => $limit, 'detail' => $detail ],
        );

        return QuotaExceededException::forUrl( $request->url, $detail, $metric, $limit );
    }

    /**
     * Pull the quota metric and limit names out of a Google error body.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $error  The `error` block of the response.
     *
     * @return array{0: string|null, 1: string|null} The quota metric and quota limit names.
     */
    protected function quotaNames( array $error ): array
    {
        $details = is_array( $error[ 'details' ] ?? null ) ? $error[ 'details' ] : [];

        foreach ( $details as $detail ) {
            if ( ! is_array( $detail ) || ! is_array( $detail[ 'metadata' ] ?? null ) ) {
                continue;
            }

            $metadata = $detail[ 'metadata' ];
            $metric   = $metadata[ 'quota_metric' ] ?? null;
            $limit    = $metadata[ 'quota_limit' ] ?? null;

            if ( is_string( $metric ) || is_string( $limit ) ) {
                return [ is_string( $metric ) ? $metric : null, is_string( $limit ) ? $limit : null ];
            }
        }

        return [ null, null ];
    }

    /**
     * The stored API key, if the driver has one.
     *
     * A driver that cannot read its storage — an unmigrated database, say —
     * is reported and treated as "no key" so the caller gets the actionable
     * "configure a key" error rather than a query exception.
     *
     * @since 1.0.0
     *
     * @return string|null The API key, or null when none is available.
     */
    protected function apiKey(): ?string
    {
        try {
            return $this->apiKeys->getApiKey();
        } catch ( Throwable $e ) {
            $this->logger->error(
                'The PageSpeed API key driver could not read its storage. Treating the key as unconfigured.',
                [ 'driver' => $this->apiKeys::class, 'error' => $e->getMessage() ],
            );

            return null;
        }
    }

    /**
     * An access token from a connected Google account, if there is one.
     *
     * @since 1.0.0
     *
     * @return string|null The bearer token, or null when no connection is usable.
     */
    protected function oauthToken(): ?string
    {
        if ( null === $this->tokens ) {
            return null;
        }

        $connection = $this->connections->resolve();

        if ( null === $connection ) {
            return null;
        }

        try {
            return $this->tokens->getValidAccessToken( $connection );
        } catch ( Throwable $e ) {
            $this->logger->warning(
                'A connected Google account was found but its access token could not be refreshed.',
                [ 'error' => $e->getMessage() ],
            );

            return null;
        }
    }

    /**
     * Encode query parameters, repeating array values under the same name.
     *
     * `category` is a repeatable parameter: PageSpeed expects
     * `category=PERFORMANCE&category=SEO`, not the indexed
     * `category[0]=…` form that http_build_query would produce.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $query  Query parameters.
     *
     * @return string The encoded query string.
     */
    protected function buildQueryString( array $query ): string
    {
        $pairs = [];

        foreach ( $query as $name => $value ) {
            foreach ( is_array( $value ) ? $value : [ $value ] as $item ) {
                $pairs[] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $item );
            }
        }

        return implode( '&', $pairs );
    }

    /**
     * The configured endpoint.
     *
     * @since 1.0.0
     *
     * @return string The runPagespeed endpoint.
     */
    protected function endpoint(): string
    {
        $endpoint = $this->config->get( 'pagespeed-insights.endpoint', self::DEFAULT_ENDPOINT );

        if ( ! is_string( $endpoint ) || '' === trim( $endpoint ) ) {
            return self::DEFAULT_ENDPOINT;
        }

        return trim( $endpoint );
    }

    /**
     * The configured categories, in response-key spelling.
     *
     * @since 1.0.0
     *
     * @return array<int, string> Category keys to request.
     */
    protected function configuredCategories(): array
    {
        $categories = $this->config->get( 'pagespeed-insights.categories', CategoryTranslator::KNOWN );

        return is_array( $categories ) && [] !== $categories ? array_values( $categories ) : CategoryTranslator::KNOWN;
    }

    /**
     * The configured request timeout in seconds.
     *
     * Falls back when the value is missing or not positive: Guzzle reads a
     * timeout of 0 as "wait forever", which would hang a queue worker on a
     * stuck endpoint.
     *
     * @since 1.0.0
     *
     * @return int Seconds to wait for a response.
     */
    protected function timeout(): int
    {
        $timeout = $this->config->get( 'pagespeed-insights.timeout', self::DEFAULT_TIMEOUT );

        return is_numeric( $timeout ) && (int) $timeout > 0 ? (int) $timeout : self::DEFAULT_TIMEOUT;
    }

    /**
     * How many opportunities to keep from each run.
     *
     * @since 1.0.0
     *
     * @return int The configured limit, never negative.
     */
    protected function opportunitiesLimit(): int
    {
        $limit = $this->config->get( 'pagespeed-insights.opportunities_limit', self::DEFAULT_OPPORTUNITIES_LIMIT );

        return is_numeric( $limit ) && (int) $limit >= 0 ? (int) $limit : self::DEFAULT_OPPORTUNITIES_LIMIT;
    }
}
