<?php

declare( strict_types=1 );

use ArtisanPackUI\Google\Models\GoogleConnection;
use ArtisanPackUI\Google\Tokens\TokenManager;
use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedClient;
use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;
use ArtisanPackUI\PageSpeedInsights\Exceptions\MissingApiKeyException;
use ArtisanPackUI\PageSpeedInsights\Exceptions\PageSpeedApiException;
use ArtisanPackUI\PageSpeedInsights\Exceptions\QuotaExceededException;
use ArtisanPackUI\PageSpeedInsights\Support\GoogleConnectionResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

/**
 * Build a client with the credentials and connection a test needs.
 *
 * @param  string|null  $apiKey  The key the repository should return, or null.
 * @param  string|null  $accessToken  The OAuth token a connection should yield, or null for no connection.
 * @param  LoggerInterface|null  $logger  A logger spy.
 *
 * @return PageSpeedClient The built client.
 */
function psiClient( ?string $apiKey = null, ?string $accessToken = null, ?LoggerInterface $logger = null ): PageSpeedClient
{
    $keys = Mockery::mock( ApiKeyRepository::class );
    $keys->shouldReceive( 'getApiKey' )->andReturn( $apiKey );
    $keys->shouldReceive( 'isConfigured' )->andReturn( null !== $apiKey );

    $connections = Mockery::mock( GoogleConnectionResolver::class );
    $tokens      = null;

    if ( null === $accessToken ) {
        $connections->shouldReceive( 'resolve' )->andReturn( null );
    } else {
        $connection = new GoogleConnection();
        $connections->shouldReceive( 'resolve' )->andReturn( $connection );

        $tokens = Mockery::mock( TokenManager::class );
        $tokens->shouldReceive( 'getValidAccessToken' )->with( $connection )->andReturn( $accessToken );
    }

    return new PageSpeedClient(
        app( HttpFactory::class ),
        app( 'config' ),
        $keys,
        $connections,
        $logger ?? Mockery::spy( LoggerInterface::class ),
        $tokens,
    );
}

describe( 'credential resolution', function (): void {
    it( 'sends the API key on a header when one is configured', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        psiClient( apiKey: 'test-key' )->test( 'https://example.com/' );

        Http::assertSent( fn ( Request $request ): bool => 'test-key' === $request->header( PageSpeedClient::API_KEY_HEADER )[ 0 ]
            && ! $request->hasHeader( 'Authorization' ) );
    } );

    it( 'never puts the API key in the URL, where Guzzle would log it', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        psiClient( apiKey: 'super-secret-key' )->test( 'https://example.com/' );

        Http::assertSent( fn ( Request $request ): bool => ! str_contains( $request->url(), 'super-secret-key' )
            && ! str_contains( $request->url(), 'key=' ) );
    } );

    it( 'prefers the API key over a connected Google account', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        psiClient( apiKey: 'test-key', accessToken: 'oauth-token' )->test( 'https://example.com/' );

        Http::assertSent( fn ( Request $request ): bool => 'test-key' === $request->header( PageSpeedClient::API_KEY_HEADER )[ 0 ]
            && ! $request->hasHeader( 'Authorization' ) );
    } );

    it( 'falls back to a bearer token from a connected Google account', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        psiClient( accessToken: 'oauth-token' )->test( 'https://example.com/' );

        Http::assertSent( fn ( Request $request ): bool => 'Bearer oauth-token' === $request->header( 'Authorization' )[ 0 ]
            && ! $request->hasHeader( PageSpeedClient::API_KEY_HEADER ) );
    } );

    it( 'warns that the OAuth fallback is unlikely to work', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        $logger = Mockery::spy( LoggerInterface::class );
        psiClient( accessToken: 'oauth-token', logger: $logger )->test( 'https://example.com/' );

        $logger->shouldHaveReceived( 'warning' )->withArgs(
            fn ( string $message ): bool => str_contains( $message, 'PAGESPEED_API_KEY' ),
        );
    } );

    it( 'refuses to run with no key and no connection, before spending a request', function (): void {
        Http::fake();

        expect( fn () => psiClient()->test( 'https://example.com/' ) )
            ->toThrow( MissingApiKeyException::class );

        Http::assertNothingSent();
    } );

    it( 'names the fix in the no-credentials message', function (): void {
        Http::fake();

        try {
            psiClient()->test( 'https://example.com/' );
        } catch ( MissingApiKeyException $e ) {
            expect( $e->getMessage() )->toContain( 'PAGESPEED_API_KEY' )
                ->and( $e->getMessage() )->toContain( 'https://example.com/' )
                ->and( $e->isRetryable() )->toBeFalse();

            return;
        }

        $this->fail( 'Expected a MissingApiKeyException.' );
    } );

    it( 'treats an unreadable key driver as no key rather than crashing', function (): void {
        Http::fake();

        $keys = Mockery::mock( ApiKeyRepository::class );
        $keys->shouldReceive( 'getApiKey' )->andThrow( new RuntimeException( 'no such table: pagespeed_configurations' ) );

        $connections = Mockery::mock( GoogleConnectionResolver::class );
        $connections->shouldReceive( 'resolve' )->andReturn( null );

        $client = new PageSpeedClient(
            app( HttpFactory::class ),
            app( 'config' ),
            $keys,
            $connections,
            Mockery::spy( LoggerInterface::class ),
        );

        expect( fn () => $client->test( 'https://example.com/' ) )->toThrow( MissingApiKeyException::class );
    } );
} );

describe( 'the request it builds', function (): void {
    it( 'repeats the category parameter rather than indexing it', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        psiClient( apiKey: 'test-key' )->test( 'https://example.com/' );

        Http::assertSent( function ( Request $request ): bool {
            expect( $request->url() )->toContain( 'category=PERFORMANCE' )
                ->and( $request->url() )->toContain( 'category=BEST_PRACTICES' )
                ->and( $request->url() )->toContain( 'category=SEO' )
                ->and( $request->url() )->not->toContain( 'category%5B0%5D' );

            return true;
        } );
    } );

    it( 'sends the strategy explicitly', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        psiClient( apiKey: 'test-key' )->test( 'https://example.com/', 'desktop' );

        Http::assertSent( fn ( Request $request ): bool => str_contains( $request->url(), 'strategy=DESKTOP' ) );
    } );

    it( 'uses the configured categories', function (): void {
        config()->set( 'pagespeed-insights.categories', [ 'performance', 'seo' ] );
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        psiClient( apiKey: 'test-key' )->test( 'https://example.com/' );

        Http::assertSent( fn ( Request $request ): bool => str_contains( $request->url(), 'category=PERFORMANCE' )
            && str_contains( $request->url(), 'category=SEO' )
            && ! str_contains( $request->url(), 'category=ACCESSIBILITY' ) );
    } );

    it( 'uses the configured endpoint', function (): void {
        config()->set( 'pagespeed-insights.endpoint', 'https://pagespeedonline.googleapis.com/pagespeedonline/v5/runPagespeed' );
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        psiClient( apiKey: 'test-key' )->test( 'https://example.com/' );

        Http::assertSent( fn ( Request $request ): bool => str_starts_with( $request->url(), 'https://pagespeedonline.googleapis.com/' ) );
    } );

    it( 'url-encodes the URL under test so its own query string survives', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        psiClient( apiKey: 'test-key' )->test( 'https://example.com/search?q=laravel&page=2' );

        Http::assertSent( fn ( Request $request ): bool => str_contains(
            $request->url(),
            'url=https%3A%2F%2Fexample.com%2Fsearch%3Fq%3Dlaravel%26page%3D2',
        ) );
    } );
} );

describe( 'failure classification', function (): void {
    it( 'maps a 429 with a configured key to quota exhaustion', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'quota-error' ), 429 ) ] );

        expect( fn () => psiClient( apiKey: 'test-key' )->test( 'https://example.com/' ) )
            ->toThrow( QuotaExceededException::class );
    } );

    it( 'carries the quota metric and limit Google named', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'quota-error' ), 429 ) ] );

        try {
            psiClient( apiKey: 'test-key' )->test( 'https://example.com/' );
        } catch ( QuotaExceededException $e ) {
            expect( $e->quotaMetric )->toBe( 'pagespeedonline.googleapis.com/default_requests' )
                ->and( $e->quotaLimit )->toBe( 'defaultPerDayPerProject' )
                ->and( $e->isRetryable() )->toBeTrue();

            return;
        }

        $this->fail( 'Expected a QuotaExceededException.' );
    } );

    it( 'maps a 429 with no configured key to a missing key, not quota', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'quota-error' ), 429 ) ] );

        try {
            psiClient( accessToken: 'oauth-token' )->test( 'https://example.com/' );
        } catch ( MissingApiKeyException $e ) {
            expect( $e )->not->toBeInstanceOf( QuotaExceededException::class )
                ->and( $e->getMessage() )->toContain( 'no API key was sent' )
                ->and( $e->isRetryable() )->toBeFalse();

            return;
        }

        $this->fail( 'Expected a MissingApiKeyException.' );
    } );

    it( 'maps other API errors to the base exception', function ( int $status ): void {
        Http::fake( [ '*' => Http::response( [ 'error' => [ 'code' => $status, 'message' => 'Bad things.' ] ], $status ) ] );

        try {
            psiClient( apiKey: 'test-key' )->test( 'https://example.com/' );
        } catch ( PageSpeedApiException $e ) {
            expect( $e )->not->toBeInstanceOf( QuotaExceededException::class )
                ->and( $e )->not->toBeInstanceOf( MissingApiKeyException::class )
                ->and( $e->status )->toBe( $status )
                ->and( $e->getMessage() )->toContain( 'Bad things.' )
                ->and( $e->isRetryable() )->toBeTrue();

            return;
        }

        $this->fail( 'Expected a PageSpeedApiException.' );
    } )->with( [ 'bad request' => [ 400 ], 'forbidden' => [ 403 ], 'server error' => [ 500 ] ] );

    it( 'maps a transport failure to the base exception', function (): void {
        Http::fake( fn (): never => throw new ConnectionException( 'cURL error 28: Operation timed out' ) );

        try {
            psiClient( apiKey: 'test-key' )->test( 'https://example.com/' );
        } catch ( PageSpeedApiException $e ) {
            expect( $e->getMessage() )->toContain( 'Could not reach' )
                ->and( $e->getPrevious() )->toBeInstanceOf( ConnectionException::class );

            return;
        }

        $this->fail( 'Expected a PageSpeedApiException.' );
    } );

    it( 'redacts a credential the transport echoed back in its message', function (): void {
        Http::fake( fn (): never => throw new ConnectionException(
            'cURL error 28: Operation timed out for https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=x&key=AIzaSuperSecret&strategy=MOBILE',
        ) );

        try {
            psiClient( apiKey: 'test-key' )->test( 'https://example.com/' );
        } catch ( PageSpeedApiException $e ) {
            expect( $e->getMessage() )->not->toContain( 'AIzaSuperSecret' )
                ->and( $e->getMessage() )->toContain( 'key=[redacted]' )
                ->and( $e->getMessage() )->toContain( 'strategy=MOBILE' );

            return;
        }

        $this->fail( 'Expected a PageSpeedApiException.' );
    } );

    it( 'throws when a successful response is not JSON', function (): void {
        Http::fake( [ '*' => Http::response( 'not json at all' ) ] );

        expect( fn () => psiClient( apiKey: 'test-key' )->test( 'https://example.com/' ) )
            ->toThrow( PageSpeedApiException::class );
    } );

    it( 'surfaces a Lighthouse runtime error from a 200', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'runtime-error' ) ) ] );

        try {
            psiClient( apiKey: 'test-key' )->test( 'https://example.com/broken' );
        } catch ( PageSpeedApiException $e ) {
            expect( $e->lighthouseErrorCode )->toBe( 'ERRORED_DOCUMENT_REQUEST' );

            return;
        }

        $this->fail( 'Expected a PageSpeedApiException.' );
    } );
} );

describe( 'a successful run', function (): void {
    it( 'returns a parsed result', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        $result = psiClient( apiKey: 'test-key' )->test( 'https://example.com/', 'desktop' );

        expect( $result->url )->toBe( 'https://example.com/' )
            ->and( $result->strategy )->toBe( 'desktop' )
            ->and( $result->scores->performance() )->toBe( 97 )
            ->and( $result->opportunities )->not->toBeEmpty();
    } );

    it( 'honors the configured opportunities limit', function (): void {
        config()->set( 'pagespeed-insights.opportunities_limit', 2 );
        Http::fake( [ '*' => Http::response( psiFixture( 'poor' ) ) ] );

        $result = psiClient( apiKey: 'test-key' )->test( 'https://example.com/slow' );

        expect( $result->opportunities )->toHaveCount( 2 );
    } );

    it( 'runs a prepared request as given', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        $result = psiClient( apiKey: 'test-key' )->run(
            new PageSpeedRequest( 'https://example.com/', 'mobile', [ 'performance' ], 'fr-FR' ),
        );

        expect( $result->scores->performance() )->toBe( 97 );

        Http::assertSent( fn ( Request $request ): bool => str_contains( $request->url(), 'locale=fr-FR' ) );
    } );
} );

describe( 'container wiring', function (): void {
    it( 'resolves the client from the container', function (): void {
        expect( app( PageSpeedClient::class ) )->toBeInstanceOf( PageSpeedClient::class );
    } );

    it( 'exposes the client through the package entry point', function (): void {
        expect( pageSpeedInsights()->client() )->toBeInstanceOf( PageSpeedClient::class );
    } );

    it( 'runs a test through the package entry point', function (): void {
        config()->set( 'pagespeed-insights.api_key', 'test-key' );
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        expect( pageSpeedInsights()->test( 'https://example.com/' )->scores->seo() )->toBe( 100 );
    } );
} );
