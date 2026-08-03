<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\Controller;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\CoreWebVitalsController;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\OpportunitiesController;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\ScoresController;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\TrendsController;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Support\ScoreBands;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HttpUser;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'pagespeed-insights.driver', 'config' );
    config()->set( 'pagespeed-insights.api_key', 'test-key' );

    $this->monitored = 'https://example.com/page';

    PageSpeedUrl::factory()->create( [ 'url' => $this->monitored ] );

    $this->actingAs( new HttpUser() );
} );

/*
|--------------------------------------------------------------------------
| The URL allowlist
|--------------------------------------------------------------------------
*/

it( 'refuses a URL this installation does not monitor', function ( string $path ): void {
    $this->getJson( $path . '?url=https://not-monitored.example/page' )
        ->assertForbidden()
        ->assertJsonPath( 'error', Controller::ERROR_URL_NOT_MONITORED );
} )->with( [
    '/pagespeed/scores',
    '/pagespeed/core-web-vitals',
    '/pagespeed/opportunities',
    '/pagespeed/trends',
] );

it( 'still refuses an unmonitored URL when external URLs are allowed', function (): void {
    // The flag widens what may be *tested*, never what may be read. Reading is
    // about this installation's own history.
    config()->set( 'pagespeed-insights.routes.allow_external_urls', true );

    $this->getJson( '/pagespeed/scores?url=https://not-monitored.example/page' )
        ->assertForbidden()
        ->assertJsonPath( 'error', Controller::ERROR_URL_NOT_MONITORED );
} );

it( 'refuses a URL that is not a testable address', function ( string $path ): void {
    $this->getJson( $path . '?url=' . urlencode( 'mailto:someone@example.com' ) )
        ->assertStatus( 422 )
        ->assertJsonPath( 'error', Controller::ERROR_INVALID_URL );
} )->with( [
    '/pagespeed/scores',
    '/pagespeed/core-web-vitals',
    '/pagespeed/opportunities',
    '/pagespeed/trends',
] );

it( 'refuses a request with no url at all', function (): void {
    $this->getJson( '/pagespeed/scores' )
        ->assertStatus( 422 )
        ->assertJsonPath( 'error', Controller::ERROR_INVALID_URL );
} );

it( 'matches a monitored URL written in a different spelling', function (): void {
    $this->getJson( '/pagespeed/scores?url=' . urlencode( 'HTTPS://Example.com/page/#team' ) )
        ->assertOk()
        ->assertJsonPath( 'url', 'https://example.com/page' );
} );

it( 'serves a URL contributed by a hook rather than stored', function (): void {
    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => array_merge(
        $urls,
        [ 'https://example.com/hooked' ],
    ) );

    $this->getJson( '/pagespeed/scores?url=https://example.com/hooked' )
        ->assertOk()
        ->assertJsonPath( 'state', ScoresController::STATE_EMPTY );
} );

/*
|--------------------------------------------------------------------------
| Scores
|--------------------------------------------------------------------------
*/

it( 'reports the empty state when nothing has been stored', function (): void {
    $this->getJson( '/pagespeed/scores?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', ScoresController::STATE_EMPTY )
        ->assertJsonPath( 'strategy', PageSpeedRequest::STRATEGY_MOBILE )
        ->assertJsonPath( 'apiKeyConfigured', true )
        ->assertJsonPath( 'result', null )
        ->assertJsonPath( 'scores', [] );
} );

it( 'reports a missing API key alongside the history rather than instead of it', function (): void {
    config()->set( 'pagespeed-insights.api_key', null );

    PageSpeedResult::factory()->create( [ 'url' => $this->monitored, 'performance_score' => 93 ] );

    $this->getJson( '/pagespeed/scores?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'apiKeyConfigured', false )
        ->assertJsonPath( 'state', ScoresController::STATE_LOADED )
        ->assertJsonPath( 'scores.0.score', 93 );
} );

it( 'serves the newest run banded', function (): void {
    PageSpeedResult::factory()->create( [
        'url'               => $this->monitored,
        'performance_score' => 20,
        'created_at'        => CarbonImmutable::now()->subDay(),
    ] );

    $newest = PageSpeedResult::factory()->create( [
        'url'                  => $this->monitored,
        'performance_score'    => 95,
        'accessibility_score'  => 72,
        'best_practices_score' => 40,
        'seo_score'            => 100,
    ] );

    $response = $this->getJson( '/pagespeed/scores?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', ScoresController::STATE_LOADED )
        ->assertJsonPath( 'result.id', $newest->getKey() );

    expect( $response->json( 'scores' ) )->toBe( [
        [ 'category' => 'performance', 'score' => 95, 'band' => ScoreBands::GOOD ],
        [ 'category' => 'accessibility', 'score' => 72, 'band' => ScoreBands::NEEDS_IMPROVEMENT ],
        [ 'category' => 'best-practices', 'score' => 40, 'band' => ScoreBands::POOR ],
        [ 'category' => 'seo', 'score' => 100, 'band' => ScoreBands::GOOD ],
    ] );

    expect( $response->json( 'labMetrics.largest-contentful-paint.value' ) )->toEqual( 1800.0 );
} );

it( 'reads the requested form factor rather than the newest run of any', function (): void {
    PageSpeedResult::factory()->desktop()->create( [
        'url'               => $this->monitored,
        'performance_score' => 40,
    ] );

    PageSpeedResult::factory()->create( [
        'url'               => $this->monitored,
        'performance_score' => 99,
    ] );

    $this->getJson( '/pagespeed/scores?url=' . urlencode( $this->monitored ) . '&strategy=desktop' )
        ->assertOk()
        ->assertJsonPath( 'strategy', PageSpeedRequest::STRATEGY_DESKTOP )
        ->assertJsonPath( 'scores.0.score', 40 );
} );

it( 'reduces an unknown form factor to mobile rather than refusing it', function (): void {
    $this->getJson( '/pagespeed/scores?url=' . urlencode( $this->monitored ) . '&strategy=watch' )
        ->assertOk()
        ->assertJsonPath( 'strategy', PageSpeedRequest::STRATEGY_MOBILE );
} );

it( 'reports a failed run with the reason it recorded', function (): void {
    PageSpeedResult::factory()->failed( 'PageSpeed returned HTTP 500 for this URL.' )->create( [
        'url' => $this->monitored,
    ] );

    $this->getJson( '/pagespeed/scores?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', ScoresController::STATE_FAILED )
        ->assertJsonPath( 'result.errorMessage', 'PageSpeed returned HTTP 500 for this URL.' )
        ->assertJsonPath( 'scores', [] );
} );

it( 'reports a degraded run as degraded, and leaves out the categories it lost', function (): void {
    PageSpeedResult::factory()->degraded()->create( [ 'url' => $this->monitored ] );

    $response = $this->getJson( '/pagespeed/scores?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', ScoresController::STATE_DEGRADED )
        ->assertJsonPath( 'result.degraded', true );

    // The two categories the response never carried are absent rather than
    // sent as nulls, so a client can tell "not measured" from "unscored".
    expect( array_column( $response->json( 'scores' ), 'category' ) )
        ->toBe( [ 'performance', 'accessibility' ] );

    expect( $response->json( 'result.warnings' ) )->not->toBeEmpty();
} );

/*
|--------------------------------------------------------------------------
| Core Web Vitals
|--------------------------------------------------------------------------
*/

it( 'serves page and origin field data side by side', function (): void {
    PageSpeedResult::factory()->create( [ 'url' => $this->monitored ] );

    $response = $this->getJson( '/pagespeed/core-web-vitals?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', CoreWebVitalsController::STATE_LOADED )
        ->assertJsonPath( 'percentile', 75 )
        ->assertJsonPath( 'page.originLevel', false )
        ->assertJsonPath( 'origin.originLevel', true );

    expect( $response->json( 'page.vitals' ) )->toBe( [
        [
            'metric'   => 'largest_contentful_paint',
            'value'    => 1900,
            'band'     => ScoreBands::GOOD,
            'category' => 'FAST',
        ],
        [
            'metric'   => 'interaction_to_next_paint',
            'value'    => 140,
            'band'     => ScoreBands::GOOD,
            'category' => 'FAST',
        ],
        [
            'metric'   => 'cumulative_layout_shift',
            'value'    => 4,
            'band'     => ScoreBands::GOOD,
            'category' => 'FAST',
        ],
    ] );
} );

it( 'says so when CrUX has nothing for the page or the origin', function (): void {
    PageSpeedResult::factory()->withoutFieldData()->create( [ 'url' => $this->monitored ] );

    $this->getJson( '/pagespeed/core-web-vitals?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', CoreWebVitalsController::STATE_NO_FIELD_DATA )
        ->assertJsonPath( 'page', null )
        ->assertJsonPath( 'origin', null );
} );

it( 'reports origin-level data as origin-level rather than as the page', function (): void {
    $result = PageSpeedResult::factory()->create( [ 'url' => $this->monitored ] );

    // CrUX substituting origin data for a low-traffic page: the page-level set
    // is real, and it is not about this page.
    $pageLevel                      = $result->field_data;
    $pageLevel[ 'origin_fallback' ] = true;

    $result->forceFill( [ 'field_data' => $pageLevel ] )->save();

    $this->getJson( '/pagespeed/core-web-vitals?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', CoreWebVitalsController::STATE_ORIGIN_LEVEL )
        ->assertJsonPath( 'page.originFallback', true );
} );

it( 'reports no field data for a failed run', function (): void {
    PageSpeedResult::factory()->failed()->create( [ 'url' => $this->monitored ] );

    $this->getJson( '/pagespeed/core-web-vitals?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', CoreWebVitalsController::STATE_FAILED );
} );

/*
|--------------------------------------------------------------------------
| Opportunities
|--------------------------------------------------------------------------
*/

it( 'serves opportunities heaviest first', function (): void {
    PageSpeedResult::factory()->poor()->create( [ 'url' => $this->monitored ] );

    $response = $this->getJson( '/pagespeed/opportunities?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', OpportunitiesController::STATE_LOADED );

    expect( array_column( $response->json( 'opportunities' ), 'id' ) )
        ->toBe( [ 'render-blocking-resources', 'unused-javascript' ] );

    expect( $response->json( 'opportunities.0.savingsMs' ) )->toEqual( 2100.0 );
    expect( $response->json( 'opportunities.0.band' ) )->toBe( ScoreBands::POOR );
} );

it( 'separates nothing found from nothing looked for', function (): void {
    PageSpeedResult::factory()->create( [ 'url' => $this->monitored, 'opportunities' => [] ] );

    $this->getJson( '/pagespeed/opportunities?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', OpportunitiesController::STATE_NONE );
} );

it( 'reports a run that never measured performance as not measured', function (): void {
    PageSpeedResult::factory()->create( [
        'url'               => $this->monitored,
        'opportunities'     => [],
        'performance_score' => null,
        'warnings'          => [
            'run_warnings'            => [],
            'unrecognized_categories' => [],
            'missing_categories'      => [ 'performance' ],
            'missing_metrics'         => [],
            'missing_field_data'      => false,
        ],
    ] );

    $this->getJson( '/pagespeed/opportunities?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', OpportunitiesController::STATE_NOT_MEASURED );
} );

/*
|--------------------------------------------------------------------------
| Trends
|--------------------------------------------------------------------------
*/

it( 'plots one series per form factor that has data', function (): void {
    foreach ( [ 10, 20, 30 ] as $index => $score ) {
        PageSpeedResult::factory()->create( [
            'url'               => $this->monitored,
            'performance_score' => $score,
            'created_at'        => CarbonImmutable::now()->subDays( 3 - $index ),
        ] );

        PageSpeedResult::factory()->desktop()->create( [
            'url'               => $this->monitored,
            'performance_score' => $score + 5,
            'created_at'        => CarbonImmutable::now()->subDays( 3 - $index ),
        ] );
    }

    $response = $this->getJson( '/pagespeed/trends?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', TrendsController::STATE_LOADED )
        ->assertJsonPath( 'metric', 'performance' )
        ->assertJsonPath( 'range', 90 )
        ->assertJsonPath( 'strategy', null )
        ->assertJsonPath( 'pointCount', 6 )
        ->assertJsonPath( 'hasGaps', false )
        ->assertJsonPath( 'truncated', false );

    expect( array_column( $response->json( 'series' ), 'strategy' ) )
        ->toBe( [ PageSpeedRequest::STRATEGY_MOBILE, PageSpeedRequest::STRATEGY_DESKTOP ] );

    // Oldest first, so a client draws left to right without sorting.
    expect( array_column( $response->json( 'series.0.points' ), 'y' ) )->toBe( [ 10, 20, 30 ] );
} );

it( 'narrows to one form factor when one is named', function (): void {
    PageSpeedResult::factory()->count( 2 )->create( [ 'url' => $this->monitored ] );
    PageSpeedResult::factory()->count( 2 )->desktop()->create( [ 'url' => $this->monitored ] );

    $response = $this->getJson(
        '/pagespeed/trends?url=' . urlencode( $this->monitored ) . '&strategy=desktop',
    )->assertOk()->assertJsonPath( 'strategy', PageSpeedRequest::STRATEGY_DESKTOP );

    expect( $response->json( 'series' ) )->toHaveCount( 1 );
    expect( $response->json( 'series.0.strategy' ) )->toBe( PageSpeedRequest::STRATEGY_DESKTOP );
} );

it( 'plots a lab metric as well as a category', function (): void {
    PageSpeedResult::factory()->count( 2 )->create( [ 'url' => $this->monitored ] );

    $response = $this->getJson(
        '/pagespeed/trends?url=' . urlencode( $this->monitored ) . '&metric=largest-contentful-paint',
    )->assertOk()->assertJsonPath( 'metric', 'largest-contentful-paint' );

    expect( $response->json( 'series.0.points.0.y' ) )->toEqual( 1800.0 );
} );

it( 'reduces an unknown metric and an unknown range to the defaults', function (): void {
    $this->getJson(
        '/pagespeed/trends?url=' . urlencode( $this->monitored ) . '&metric=vibes&range=100000',
    )
        ->assertOk()
        ->assertJsonPath( 'metric', 'performance' )
        ->assertJsonPath( 'range', 90 );
} );

it( 'draws a run that lost the measurement as a gap rather than a zero', function (): void {
    PageSpeedResult::factory()->count( 2 )->create( [ 'url' => $this->monitored ] );

    PageSpeedResult::factory()->degraded()->create( [
        'url'        => $this->monitored,
        'created_at' => CarbonImmutable::now(),
    ] );

    $response = $this->getJson( '/pagespeed/trends?url=' . urlencode( $this->monitored ) . '&metric=seo' )
        ->assertOk()
        ->assertJsonPath( 'hasGaps', true );

    expect( $response->json( 'series.0.points.2.y' ) )->toBeNull();
} );

it( 'excludes failed runs rather than plotting them as zero', function (): void {
    PageSpeedResult::factory()->count( 2 )->create( [ 'url' => $this->monitored, 'performance_score' => 80 ] );
    PageSpeedResult::factory()->failed()->create( [ 'url' => $this->monitored ] );

    $response = $this->getJson( '/pagespeed/trends?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'pointCount', 2 );

    expect( $response->json( 'series.0.points' ) )->toHaveCount( 2 );
} );

it( 'reports one measurement as a dot rather than a trend', function (): void {
    PageSpeedResult::factory()->create( [ 'url' => $this->monitored ] );

    $this->getJson( '/pagespeed/trends?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', TrendsController::STATE_INSUFFICIENT )
        ->assertJsonPath( 'series', [] )
        ->assertJsonPath( 'pointCount', 1 );
} );

it( 'separates a URL with no history from one whose history is out of range', function (): void {
    $this->getJson( '/pagespeed/trends?url=' . urlencode( $this->monitored ) )
        ->assertOk()
        ->assertJsonPath( 'state', TrendsController::STATE_EMPTY )
        ->assertJsonPath( 'hasOlderHistory', false );

    PageSpeedResult::factory()->count( 2 )->create( [
        'url'        => $this->monitored,
        'created_at' => CarbonImmutable::now()->subDays( 200 ),
    ] );

    $this->getJson( '/pagespeed/trends?url=' . urlencode( $this->monitored ) . '&range=30' )
        ->assertOk()
        ->assertJsonPath( 'state', TrendsController::STATE_OUT_OF_RANGE )
        ->assertJsonPath( 'hasOlderHistory', true );
} );
