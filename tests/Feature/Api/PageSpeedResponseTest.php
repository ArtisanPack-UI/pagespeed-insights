<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedResponse;
use ArtisanPackUI\PageSpeedInsights\Data\Opportunity;
use ArtisanPackUI\PageSpeedInsights\Data\TestResult;
use ArtisanPackUI\PageSpeedInsights\Exceptions\PageSpeedApiException;
use Psr\Log\LoggerInterface;

/**
 * Parse a fixture, capturing every warning the parser emitted.
 *
 * @param  string  $fixture  The fixture name.
 * @param  PageSpeedRequest|null  $request  The request that produced it.
 * @param  int  $limit  The opportunities limit.
 *
 * @return array{0: TestResult, 1: object} The parsed result and the logger spy.
 */
function parseFixture( string $fixture, ?PageSpeedRequest $request = null, int $limit = 10 ): array
{
    $logger = Mockery::spy( LoggerInterface::class );
    $result = ( new PageSpeedResponse(
        psiFixture( $fixture ),
        $request ?? new PageSpeedRequest( 'https://example.com/' ),
        $logger,
        $limit,
    ) )->toTestResult();

    return [ $result, $logger ];
}

/**
 * Assert a log level was called with a context entry.
 *
 * @param  object  $logger  The logger spy.
 * @param  string  $level  The log level.
 * @param  string  $key  The context key.
 * @param  mixed  $value  The expected context value.
 *
 * @return void
 */
function expectLogged( object $logger, string $level, string $key, mixed $value ): void
{
    $logger->shouldHaveReceived( $level )->withArgs(
        fn ( string $message, array $context = [] ): bool => ( $context[ $key ] ?? null ) === $value,
    );
}

describe( 'a healthy result', function (): void {
    it( 'reads the four category scores as 0-100 integers', function (): void {
        [ $result ] = parseFixture( 'healthy' );

        expect( $result->scores->performance() )->toBe( 97 )
            ->and( $result->scores->accessibility() )->toBe( 100 )
            ->and( $result->scores->bestPractices() )->toBe( 96 )
            ->and( $result->scores->seo() )->toBe( 100 );
    } );

    it( 'reads the five lab metrics with their display values', function (): void {
        [ $result ] = parseFixture( 'healthy' );

        expect( $result->labMetrics->firstContentfulPaint() )->toBe( 1123.44 )
            ->and( $result->labMetrics->largestContentfulPaint() )->toBe( 1834.21 )
            ->and( $result->labMetrics->totalBlockingTime() )->toBe( 45.0 )
            ->and( $result->labMetrics->cumulativeLayoutShift() )->toBe( 0.012 )
            ->and( $result->labMetrics->speedIndex() )->toBe( 1502.91 )
            ->and( $result->labMetrics->display( 'largest-contentful-paint' ) )->toBe( '1.8 s' )
            ->and( $result->labMetrics->missing() )->toBe( [] );
    } );

    it( 'reads page-level and origin-level field data', function (): void {
        [ $result ] = parseFixture( 'healthy' );

        expect( $result->hasFieldData() )->toBeTrue()
            ->and( $result->fieldData->overallCategory() )->toBe( 'FAST' )
            ->and( $result->fieldData->isOriginLevel() )->toBeFalse()
            ->and( $result->fieldData->isOriginFallback() )->toBeFalse()
            ->and( $result->fieldData->largestContentfulPaint()[ 'percentile' ] )->toBe( 1893 )
            ->and( $result->fieldData->interactionToNextPaint()[ 'category' ] )->toBe( 'FAST' )
            ->and( $result->originFieldData->isOriginLevel() )->toBeTrue();
    } );

    it( 'keeps the distribution buckets for each field metric', function (): void {
        [ $result ] = parseFixture( 'healthy' );

        expect( $result->fieldData->largestContentfulPaint()[ 'distributions' ] )->toHaveCount( 3 );
    } );

    it( 'reads the run metadata', function (): void {
        [ $result ] = parseFixture( 'healthy' );

        expect( $result->url )->toBe( 'https://example.com/' )
            ->and( $result->strategy )->toBe( 'mobile' )
            ->and( $result->finalUrl )->toBe( 'https://example.com/' )
            ->and( $result->lighthouseVersion )->toBe( '12.6.1' )
            ->and( $result->analyzedAt?->toIso8601String() )->toStartWith( '2026-08-01T14:22:31' );
    } );

    it( 'reports nothing to explain', function (): void {
        [ $result ] = parseFixture( 'healthy' );

        expect( $result->hasWarnings() )->toBeFalse()
            ->and( $result->runWarnings )->toBe( [] )
            ->and( $result->unrecognizedCategories )->toBe( [] )
            ->and( $result->missingCategories )->toBe( [] );
    } );

    it( 'keeps the raw payload for callers that store it', function (): void {
        [ $result ] = parseFixture( 'healthy' );

        expect( $result->raw )->toHaveKey( 'lighthouseResult' );
    } );
} );

describe( 'opportunities', function (): void {
    it( 'keeps only actionable audits', function (): void {
        [ $result ] = parseFixture( 'healthy' );

        $ids = array_map( fn ( Opportunity $o ): string => $o->id, $result->opportunities );

        expect( $ids )->toContain( 'render-blocking-resources', 'unused-javascript', 'modern-image-formats' )
            ->and( $ids )->not->toContain( 'uses-long-cache-ttl' );
    } );

    it( 'drops an audit that already passes even when it carries a savings estimate', function (): void {
        [ $result ] = parseFixture( 'healthy' );

        $ids = array_map( fn ( Opportunity $o ): string => $o->id, $result->opportunities );

        expect( $ids )->not->toContain( 'uses-responsive-images' );
    } );

    it( 'drops diagnostics and informational audits Lighthouse attaches savings to', function ( string $fixture, string $auditId ): void {
        [ $result ] = parseFixture( $fixture );

        $ids = array_map( fn ( Opportunity $o ): string => $o->id, $result->opportunities );

        expect( $ids )->not->toContain( $auditId );
    } )->with( [
        'notApplicable diagnostic'      => [ 'healthy', 'long-tasks' ],
        'informative insight'           => [ 'healthy', 'lcp-breakdown-insight' ],
        'notApplicable on a poor page'  => [ 'poor', 'long-tasks' ],
    ] );

    it( 'drops an audit whose estimated savings are all zero', function (): void {
        $payload = psiFixture( 'healthy' );

        $payload[ 'lighthouseResult' ][ 'audits' ][ 'unused-javascript' ][ 'score' ]                         = 0.4;
        $payload[ 'lighthouseResult' ][ 'audits' ][ 'unused-javascript' ][ 'metricSavings' ]                 = [ 'FCP' => 0, 'LCP' => 0 ];
        $payload[ 'lighthouseResult' ][ 'audits' ][ 'unused-javascript' ][ 'details' ][ 'overallSavingsMs' ] = 0;

        $result = ( new PageSpeedResponse( $payload, new PageSpeedRequest( 'https://example.com/' ), Mockery::spy( LoggerInterface::class ) ) )->toTestResult();

        $ids = array_map( fn ( Opportunity $o ): string => $o->id, $result->opportunities );

        expect( $ids )->not->toContain( 'unused-javascript' );
    } );

    it( 'reports no opportunities for a page with nothing to fix', function (): void {
        $payload = psiFixture( 'healthy' );

        foreach ( array_keys( $payload[ 'lighthouseResult' ][ 'audits' ] ) as $auditId ) {
            $payload[ 'lighthouseResult' ][ 'audits' ][ $auditId ][ 'score' ]         = 1;
            $payload[ 'lighthouseResult' ][ 'audits' ][ $auditId ][ 'metricSavings' ] = [ 'LCP' => 0, 'FCP' => 0 ];
        }

        $result = ( new PageSpeedResponse( $payload, new PageSpeedRequest( 'https://example.com/' ), Mockery::spy( LoggerInterface::class ) ) )->toTestResult();

        expect( $result->opportunities )->toBe( [] );
    } );

    it( 'does not read a measured millisecond value as a saving', function (): void {
        [ $result ] = parseFixture( 'healthy' );

        $opportunity = collect( $result->opportunities )->firstWhere( 'id', 'mainthread-work-breakdown' );

        expect( $opportunity->savingsMs )->toBeNull()
            ->and( $opportunity->weight() )->toBe( 60.0 );
    } );

    it( 'falls back to the largest per-metric saving when there is no overall one', function (): void {
        [ $result ] = parseFixture( 'healthy' );

        $opportunity = collect( $result->opportunities )->firstWhere( 'id', 'modern-image-formats' );

        expect( $opportunity->savingsMs )->toBeNull()
            ->and( $opportunity->weight() )->toBe( 90.0 );
    } );

    it( 'orders them by estimated saving', function (): void {
        [ $result ] = parseFixture( 'poor' );

        $weights = array_map( fn ( Opportunity $o ): float => $o->weight(), $result->opportunities );
        $sorted  = $weights;
        rsort( $sorted );

        expect( $weights )->toBe( $sorted )
            ->and( $result->opportunities[ 0 ]->id )->toBe( 'render-blocking-resources' );
    } );

    it( 'prunes to the configured limit', function (): void {
        [ $result ] = parseFixture( 'poor' );

        expect( $result->opportunities )->toHaveCount( 10 );
    } );

    it( 'honors a smaller limit', function (): void {
        [ $result ] = parseFixture( 'poor', limit: 3 );

        expect( $result->opportunities )->toHaveCount( 3 )
            ->and( $result->opportunities[ 2 ]->id )->toBe( 'unused-css-rules' );
    } );

    it( 'reads the savings and per-metric estimates', function (): void {
        [ $result ] = parseFixture( 'healthy' );

        $opportunity = collect( $result->opportunities )->firstWhere( 'id', 'unused-javascript' );

        expect( $opportunity->title )->toBe( 'Reduce unused JavaScript' )
            ->and( $opportunity->score )->toBe( 0.83 )
            ->and( $opportunity->savingsMs )->toBe( 320.0 )
            ->and( $opportunity->displayValue )->toBe( 'Potential savings of 42 KiB' )
            ->and( $opportunity->metricSavings )->toBe( [ 'FCP' => 150.0, 'LCP' => 300.0 ] );
    } );

    it( 'passes the pruned list through the ap.pageSpeed.opportunities filter', function (): void {
        addFilter( PageSpeedResponse::FILTER_OPPORTUNITIES, fn ( array $opportunities ): array => array_slice( $opportunities, 0, 1 ) );

        [ $result ] = parseFixture( 'poor' );

        expect( $result->opportunities )->toHaveCount( 1 );
    } );

    it( 'hands the filter the request that produced the list', function (): void {
        $seen = null;

        addFilter(
            PageSpeedResponse::FILTER_OPPORTUNITIES,
            function ( array $opportunities, PageSpeedRequest $request ) use ( &$seen ): array {
                $seen = $request;

                return $opportunities;
            },
        );

        parseFixture( 'healthy', new PageSpeedRequest( 'https://example.com/', 'desktop' ) );

        expect( $seen?->strategy )->toBe( 'desktop' );
    } );

    it( 'ignores a filter that returns something other than a list', function (): void {
        addFilter( PageSpeedResponse::FILTER_OPPORTUNITIES, fn (): string => 'nope' );

        [ $result, $logger ] = parseFixture( 'healthy' );

        expect( $result->opportunities )->not->toBeEmpty();
        $logger->shouldHaveReceived( 'warning' );
    } );

    it( 'drops filter entries that are not opportunities', function (): void {
        addFilter( PageSpeedResponse::FILTER_OPPORTUNITIES, fn ( array $opportunities ): array => [ ...$opportunities, 'junk' ] );

        [ $result, $logger ] = parseFixture( 'healthy' );

        expect( $result->opportunities )->each->toBeInstanceOf( Opportunity::class );
        expectLogged( $logger, 'warning', 'dropped', 1 );
    } );
} );

describe( 'tolerated gaps', function (): void {
    it( 'keeps a category it has no accessor for, and warns', function (): void {
        [ $result, $logger ] = parseFixture( 'emerging-categories' );

        expect( $result->scores->get( 'agentic-browsing' ) )->toBe( 52 )
            ->and( $result->unrecognizedCategories )->toBe( [ 'agentic-browsing' ] );

        expectLogged( $logger, 'warning', 'category', 'agentic-browsing' );
    } );

    it( 'warns when a requested category is missing from the response', function (): void {
        [ $result, $logger ] = parseFixture( 'emerging-categories' );

        expect( $result->missingCategories )->toBe( [ 'seo' ] )
            ->and( $result->scores->seo() )->toBeNull();

        expectLogged( $logger, 'warning', 'category', 'seo' );
    } );

    it( 'warns when a lab metric is missing from the response', function (): void {
        [ $result, $logger ] = parseFixture( 'emerging-categories' );

        expect( $result->missingMetrics )->toBe( [ 'speed-index' ] )
            ->and( $result->labMetrics->speedIndex() )->toBeNull();

        expectLogged( $logger, 'warning', 'metric', 'speed-index' );
    } );

    it( 'warns when CrUX has no field data at all', function (): void {
        [ $result, $logger ] = parseFixture( 'no-field-data' );

        expect( $result->hasFieldData() )->toBeFalse()
            ->and( $result->fieldData )->toBeNull()
            ->and( $result->originFieldData )->toBeNull()
            ->and( $result->warnings()[ 'missing_field_data' ] )->toBeTrue();

        $logger->shouldHaveReceived( 'warning' )->withArgs(
            fn ( string $message ): bool => str_contains( $message, 'Chrome UX Report' ),
        );
    } );

    it( 'still reads scores and metrics when there is no field data', function (): void {
        [ $result ] = parseFixture( 'no-field-data' );

        expect( $result->scores->performance() )->toBe( 88 )
            ->and( $result->labMetrics->largestContentfulPaint() )->toBe( 2410.9 );
    } );

    it( 'records the origin fallback when CrUX substituted origin data', function (): void {
        [ $result ] = parseFixture( 'poor' );

        expect( $result->fieldData->isOriginFallback() )->toBeTrue();
    } );

    it( 'carries Lighthouse own run warnings instead of dropping them', function (): void {
        [ $result, $logger ] = parseFixture( 'poor' );

        expect( $result->runWarnings )->toHaveCount( 2 )
            ->and( $result->runWarnings[ 0 ] )->toContain( 'redirected' );

        expectLogged( $logger, 'warning', 'run_warnings', $result->runWarnings );
    } );

    it( 'reads an unscored category as null rather than zero', function (): void {
        $logger = Mockery::spy( LoggerInterface::class );

        $payload                                                           = psiFixture( 'healthy' );
        $payload[ 'lighthouseResult' ][ 'categories' ][ 'seo' ][ 'score' ] = null;

        $result = ( new PageSpeedResponse( $payload, new PageSpeedRequest( 'https://example.com/' ), $logger ) )->toTestResult();

        expect( $result->scores->seo() )->toBeNull()
            ->and( $result->scores->has( 'seo' ) )->toBeTrue();
    } );
} );

describe( 'failures', function (): void {
    it( 'throws on a 200 that carries a Lighthouse runtime error', function (): void {
        parseFixture( 'runtime-error' );
    } )->throws( PageSpeedApiException::class );

    it( 'preserves the Lighthouse error code and message verbatim', function (): void {
        try {
            parseFixture( 'runtime-error' );
        } catch ( PageSpeedApiException $e ) {
            expect( $e->lighthouseErrorCode )->toBe( 'ERRORED_DOCUMENT_REQUEST' )
                ->and( $e->getMessage() )->toContain( 'Status code: 503' )
                ->and( $e->status )->toBe( 200 );

            return;
        }

        $this->fail( 'Expected a PageSpeedApiException.' );
    } );

    it( 'logs the runtime error as an error, not a warning', function (): void {
        $logger = Mockery::spy( LoggerInterface::class );

        try {
            ( new PageSpeedResponse( psiFixture( 'runtime-error' ), new PageSpeedRequest( 'https://example.com/broken' ), $logger ) )->toTestResult();
        } catch ( PageSpeedApiException ) {
            // Expected.
        }

        expectLogged( $logger, 'error', 'code', 'ERRORED_DOCUMENT_REQUEST' );
    } );

    it( 'throws when the payload is not a PageSpeed result at all', function (): void {
        ( new PageSpeedResponse( [ 'something' => 'else' ], new PageSpeedRequest( 'https://example.com/' ), Mockery::spy( LoggerInterface::class ) ) )->toTestResult();
    } )->throws( PageSpeedApiException::class );

    it( 'tolerates a response with no categories, audits, or timestamp', function (): void {
        $result = ( new PageSpeedResponse(
            [ 'lighthouseResult' => [] ],
            new PageSpeedRequest( 'https://example.com/' ),
            Mockery::spy( LoggerInterface::class ),
        ) )->toTestResult();

        expect( $result->scores->all() )->toBe( [] )
            ->and( $result->missingCategories )->toHaveCount( 4 )
            ->and( $result->missingMetrics )->toHaveCount( 5 )
            ->and( $result->analyzedAt )->toBeNull()
            ->and( $result->opportunities )->toBe( [] );
    } );
} );
