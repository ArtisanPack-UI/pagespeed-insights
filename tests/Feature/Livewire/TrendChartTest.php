<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard;
use ArtisanPackUI\PageSpeedInsights\Livewire\TrendChart;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Support\UiComponentsInstalled;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'pagespeed-insights.driver', 'config' );
    config()->set( 'pagespeed-insights.api_key', 'test-key' );
} );

/**
 * Store one completed run a given number of days ago.
 *
 * @param  int  $daysAgo  How long ago the run happened.
 * @param  array<string, mixed>  $attributes  Attribute overrides.
 *
 * @return PageSpeedResult The stored run.
 */
function trendResult( int $daysAgo, array $attributes = [] ): PageSpeedResult
{
    $at = CarbonImmutable::now()->subDays( $daysAgo );

    return PageSpeedResult::factory()->create( array_merge( [
        'url'        => 'https://example.com/page',
        'strategy'   => 'mobile',
        'fetched_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ], $attributes ) );
}

it( 'renders the empty state when nothing has been stored', function (): void {
    Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', TrendChart::STATE_EMPTY )
        ->assertSet( 'series', [] )
        ->assertSet( 'pointCount', 0 )
        ->assertSee( 'No PageSpeed test has run for this URL yet.' );
} );

it( 'refuses to draw a trend from a single measurement', function (): void {
    // One point is a dot, not a direction, and a chart invites a reader to
    // conclude something about a direction that has not been measured yet.
    trendResult( 1, [ 'performance_score' => 90 ] );

    Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', TrendChart::STATE_INSUFFICIENT )
        ->assertSet( 'pointCount', 1 )
        ->assertSet( 'series', [] )
        ->assertSee( 'Not enough history yet' )
        ->assertSee( 'once a second test has run' );
} );

it( 'draws the trend once there are two measurements', function (): void {
    trendResult( 10, [ 'performance_score' => 62 ] );
    trendResult( 2, [ 'performance_score' => 91 ] );

    $component = Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', TrendChart::STATE_LOADED )
        ->assertSet( 'pointCount', 2 );

    $series = $component->get( 'series' );

    expect( $series )->toHaveCount( 1 );
    expect( $series[ 0 ][ 'name' ] )->toBe( 'Mobile' );
    expect( array_column( $series[ 0 ][ 'data' ], 'y' ) )->toBe( [ 62, 91 ] );
} );

it( 'plots mobile and desktop as separate series', function (): void {
    trendResult( 10, [ 'performance_score' => 40 ] );
    trendResult( 2, [ 'performance_score' => 55 ] );
    trendResult( 10, [ 'strategy' => 'desktop', 'performance_score' => 88 ] );
    trendResult( 2, [ 'strategy' => 'desktop', 'performance_score' => 92 ] );

    $series = Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'pointCount', 4 )
        ->get( 'series' );

    expect( array_column( $series, 'name' ) )->toBe( [ 'Mobile', 'Desktop' ] );
    expect( array_column( $series[ 0 ][ 'data' ], 'y' ) )->toBe( [ 40, 55 ] );
    expect( array_column( $series[ 1 ][ 'data' ], 'y' ) )->toBe( [ 88, 92 ] );
} );

it( 'leaves off a form factor that was never tested rather than plotting an empty series', function (): void {
    trendResult( 10, [ 'performance_score' => 40 ] );
    trendResult( 2, [ 'performance_score' => 55 ] );

    $series = Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->get( 'series' );

    expect( array_column( $series, 'name' ) )->toBe( [ 'Mobile' ] );
} );

it( 'excludes failed runs rather than plotting them as zero', function (): void {
    // A run that produced no measurement is not a score of zero, and averaging
    // one in renders an outage as a catastrophic regression.
    trendResult( 10, [ 'performance_score' => 90 ] );
    trendResult( 5, [ 'performance_score' => 92 ] );

    PageSpeedResult::factory()->failed()->create( [
        'url'        => 'https://example.com/page',
        'strategy'   => 'mobile',
        'created_at' => CarbonImmutable::now()->subDays( 2 ),
    ] );

    $series = Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'pointCount', 2 )
        ->assertSet( 'hasGaps', false )
        ->get( 'series' );

    expect( array_column( $series[ 0 ][ 'data' ], 'y' ) )->toBe( [ 90, 92 ] );
} );

it( 'plots a completed run that lost the measurement as a gap, not as a skipped point', function (): void {
    // Skipping the run entirely joins the line straight across the hole, which
    // is the same lie as plotting a zero told more quietly.
    trendResult( 10, [ 'performance_score' => 90 ] );
    trendResult( 5, [
        'performance_score' => null,
        'warnings'          => [ 'missing_categories' => [ 'performance' ] ],
    ] );
    trendResult( 2, [ 'performance_score' => 88 ] );

    $component = Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', TrendChart::STATE_LOADED )
        ->assertSet( 'pointCount', 2 )
        ->assertSet( 'hasGaps', true )
        ->assertSee( 'The line has gaps' );

    $series = $component->get( 'series' );

    expect( $series[ 0 ][ 'data' ] )->toHaveCount( 3 );
    expect( array_column( $series[ 0 ][ 'data' ], 'y' ) )->toBe( [ 90, null, 88 ] );
} );

it( 'filters history to the selected range', function (): void {
    trendResult( 200, [ 'performance_score' => 20 ] );
    trendResult( 5, [ 'performance_score' => 90 ] );
    trendResult( 2, [ 'performance_score' => 92 ] );

    $series = Livewire::test( TrendChart::class, [
        'url'   => 'https://example.com/page',
        'range' => 30,
    ] )
        ->assertSet( 'range', 30 )
        ->assertSet( 'pointCount', 2 )
        ->get( 'series' );

    expect( array_column( $series[ 0 ][ 'data' ], 'y' ) )->toBe( [ 90, 92 ] );
} );

it( 'separates history the range excludes from having no history at all', function (): void {
    // Widening the range and running a test are different remedies for what
    // otherwise looks like the same blank chart.
    trendResult( 200, [ 'performance_score' => 20 ] );
    trendResult( 190, [ 'performance_score' => 25 ] );

    Livewire::test( TrendChart::class, [
        'url'   => 'https://example.com/page',
        'range' => 30,
    ] )
        ->assertSet( 'state', TrendChart::STATE_OUT_OF_RANGE )
        ->assertSet( 'hasOlderHistory', true )
        ->assertSee( 'Nothing tested in this range' )
        ->assertSee( 'wider range' );
} );

it( 'points at a wider range when only one measurement falls inside the current one', function (): void {
    trendResult( 200, [ 'performance_score' => 20 ] );
    trendResult( 2, [ 'performance_score' => 90 ] );

    Livewire::test( TrendChart::class, [
        'url'   => 'https://example.com/page',
        'range' => 30,
    ] )
        ->assertSet( 'state', TrendChart::STATE_INSUFFICIENT )
        ->assertSet( 'hasOlderHistory', true )
        ->assertSee( 'wider range' )
        ->assertDontSee( 'once a second test has run' );
} );

it( 'rebuilds the chart when the range selector changes', function (): void {
    trendResult( 200, [ 'performance_score' => 20 ] );
    trendResult( 190, [ 'performance_score' => 25 ] );
    trendResult( 2, [ 'performance_score' => 90 ] );

    Livewire::test( TrendChart::class, [
        'url'   => 'https://example.com/page',
        'range' => 30,
    ] )
        ->assertSet( 'state', TrendChart::STATE_INSUFFICIENT )
        ->set( 'range', 365 )
        ->assertSet( 'state', TrendChart::STATE_LOADED )
        ->assertSet( 'pointCount', 3 );
} );

it( 'plots a lab metric when one is selected', function (): void {
    trendResult( 10, [ 'lab_metrics' => [ 'total-blocking-time' => [ 'value' => 210.0, 'display' => '210 ms' ] ] ] );
    trendResult( 2, [ 'lab_metrics' => [ 'total-blocking-time' => [ 'value' => 95.5, 'display' => '96 ms' ] ] ] );

    $component = Livewire::test( TrendChart::class, [
        'url'    => 'https://example.com/page',
        'metric' => 'total-blocking-time',
    ] )
        ->assertSet( 'metric', 'total-blocking-time' )
        ->assertSet( 'state', TrendChart::STATE_LOADED );

    expect( array_column( $component->get( 'series' )[ 0 ][ 'data' ], 'y' ) )->toBe( [ 210.0, 95.5 ] );

    // A lab metric is not a 0-100 score, so the axis must not be pinned to one.
    expect( $component->instance()->chartOptions()[ 'yaxis' ][ 'max' ] )->toBeNull();
} );

it( 'rebuilds the chart when the metric selector changes', function (): void {
    trendResult( 10, [
        'performance_score' => 90,
        'lab_metrics'       => [ 'speed-index' => [ 'value' => 1600.0, 'display' => '1.6 s' ] ],
    ] );
    trendResult( 2, [
        'performance_score' => 95,
        'lab_metrics'       => [ 'speed-index' => [ 'value' => 1200.0, 'display' => '1.2 s' ] ],
    ] );

    $component = Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] );

    expect( array_column( $component->get( 'series' )[ 0 ][ 'data' ], 'y' ) )->toBe( [ 90, 95 ] );

    $component->set( 'metric', 'speed-index' );

    expect( array_column( $component->get( 'series' )[ 0 ][ 'data' ], 'y' ) )->toBe( [ 1600.0, 1200.0 ] );
} );

it( 'treats a lab metric the run never carried as a gap', function (): void {
    trendResult( 10, [ 'lab_metrics' => [ 'speed-index' => [ 'value' => 1600.0, 'display' => '1.6 s' ] ] ] );
    trendResult( 5, [ 'lab_metrics' => [] ] );
    trendResult( 2, [ 'lab_metrics' => [ 'speed-index' => [ 'value' => 1200.0, 'display' => '1.2 s' ] ] ] );

    $component = Livewire::test( TrendChart::class, [
        'url'    => 'https://example.com/page',
        'metric' => 'speed-index',
    ] )
        ->assertSet( 'hasGaps', true )
        ->assertSet( 'pointCount', 2 );

    expect( array_column( $component->get( 'series' )[ 0 ][ 'data' ], 'y' ) )->toBe( [ 1600.0, null, 1200.0 ] );
} );

it( 'pins the axis to 0-100 for a category score', function (): void {
    // An auto-scaled axis turns three points of noise in the nineties into a
    // jagged mountain range.
    $options = Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->instance()
        ->chartOptions();

    expect( $options[ 'yaxis' ][ 'min' ] )->toBe( 0 );
    expect( $options[ 'yaxis' ][ 'max' ] )->toBe( 100 );
    expect( $options[ 'xaxis' ][ 'type' ] )->toBe( 'datetime' );
} );

it( 'reduces an unrecognised metric to performance rather than querying for it', function ( mixed $metric ): void {
    Livewire::test( TrendChart::class, [
        'url'    => 'https://example.com/page',
        'metric' => $metric,
    ] )->assertSet( 'metric', 'performance' );
} )->with( [
    'an unknown audit' => [ 'time-to-interactive' ],
    'a column name'    => [ 'performance_score' ],
    'an injection'     => [ 'performance; drop table pagespeed_results' ],
    'a blank string'   => [ '' ],
] );

it( 'reduces an unoffered range to the default rather than clamping it', function ( mixed $range ): void {
    // A payload naming 100,000 days is not a request for a year; it is a
    // request the component does not honour.
    Livewire::test( TrendChart::class, [
        'url'   => 'https://example.com/page',
        'range' => $range,
    ] )->assertSet( 'range', 90 );
} )->with( [
    'far too wide'      => [ 100000 ],
    'zero'              => [ 0 ],
    'negative'          => [ -30 ],
    'an unoffered span' => [ 45 ],
] );

it( 'accepts a range written as a Blade string attribute', function (): void {
    // `<livewire:pagespeed-trend-chart range="30" />` passes a string, which
    // is the ordinary way this component is mounted from a template.
    Livewire::test( TrendChart::class, [
        'url'   => 'https://example.com/page',
        'range' => '30',
    ] )->assertSet( 'range', 30 );
} );

it( 'reduces an unoffered range set from the browser', function (): void {
    trendResult( 10, [ 'performance_score' => 90 ] );
    trendResult( 2, [ 'performance_score' => 92 ] );

    Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->set( 'range', 100000 )
        ->assertSet( 'range', 90 )
        ->assertSet( 'state', TrendChart::STATE_LOADED );
} );

it( 'reduces an unrecognised metric set from the browser', function (): void {
    Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->set( 'metric', 'raw_response' )
        ->assertSet( 'metric', 'performance' );
} );

it( 'offers every category and lab metric in the selector', function (): void {
    $options = Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->instance()
        ->metricOptions();

    expect( array_column( $options, 'value' ) )->toBe( [
        'performance',
        'accessibility',
        'best-practices',
        'seo',
        'first-contentful-paint',
        'largest-contentful-paint',
        'total-blocking-time',
        'cumulative-layout-shift',
        'speed-index',
    ] );
} );

it( 'offers exactly the ranges it will honour', function (): void {
    $options = Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->instance()
        ->rangeOptions();

    expect( array_column( $options, 'value' ) )->toBe( TrendChart::RANGES );
} );

it( 'reads history for its own URL only', function (): void {
    trendResult( 10, [ 'performance_score' => 90 ] );
    trendResult( 2, [ 'performance_score' => 92 ] );
    trendResult( 2, [ 'url' => 'https://example.com/other', 'performance_score' => 10 ] );

    Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'pointCount', 2 );
} );

it( 'picks up a run the score card announced, on either form factor', function ( string $strategy ): void {
    trendResult( 10, [ 'strategy' => $strategy, 'performance_score' => 90 ] );

    $component = Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', TrendChart::STATE_INSUFFICIENT );

    trendResult( 0, [ 'strategy' => $strategy, 'performance_score' => 95 ] );

    $component->dispatch( ScoreCard::EVENT_RESULT_STORED, url: 'https://example.com/page', strategy: $strategy )
        ->assertSet( 'state', TrendChart::STATE_LOADED )
        ->assertSet( 'pointCount', 2 );
} )->with( [ 'mobile', 'desktop' ] );

it( 'ignores a run announced for another URL', function (): void {
    trendResult( 10, [ 'performance_score' => 90 ] );

    $component = Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] );

    trendResult( 0, [ 'performance_score' => 95 ] );

    $component->dispatch( ScoreCard::EVENT_RESULT_STORED, url: 'https://example.com/other', strategy: 'mobile' )
        ->assertSet( 'pointCount', 1 );
} );

it( 'renders an install notice instead of exploding when the component library is absent', function (): void {
    UiComponentsInstalled::setForTesting( false );

    Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'uiComponentsInstalled', false )
        ->assertSee( 'composer require artisanpack-ui/livewire-ui-components' );
} );

it( 'refuses a URL the browser asks it to chart', function (): void {
    Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->set( 'url', 'https://attacker.example/pwn' );
} )->throws( CannotUpdateLockedPropertyException::class );

it( 'refuses series data the browser asks it to plot', function (): void {
    // The series is derived from stored history and handed straight to the
    // charting library. There is no reason for a client to author it.
    Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->set( 'series', [ [ 'name' => 'Injected', 'data' => [] ] ] );
} )->throws( CannotUpdateLockedPropertyException::class );

it( 'never loads the columns it does not plot', function (): void {
    // raw_response runs to hundreds of kilobytes a row. Selecting it for a
    // chart that reads none of it turns a year of history into a query that
    // can exhaust the request's memory limit outright.
    expect( TrendChart::PLOTTED_COLUMNS )
        ->not->toContain( 'raw_response' )
        ->not->toContain( 'field_data' )
        ->not->toContain( 'origin_field_data' );
} );

it( 'still reads every measurement it charts from the columns it selects', function (): void {
    trendResult( 10, [
        'performance_score' => 90,
        'lab_metrics'       => [ 'speed-index' => [ 'value' => 1600.0, 'display' => '1.6 s' ] ],
    ] );
    trendResult( 2, [
        'performance_score' => 95,
        'lab_metrics'       => [ 'speed-index' => [ 'value' => 1200.0, 'display' => '1.2 s' ] ],
    ] );

    // The narrowed select must not cost the chart a measurement, on either
    // the category path or the lab metric one.
    $component = Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'pointCount', 2 );

    expect( array_column( $component->get( 'series' )[ 0 ][ 'data' ], 'y' ) )->toBe( [ 90, 95 ] );

    $component->set( 'metric', 'speed-index' );

    expect( array_column( $component->get( 'series' )[ 0 ][ 'data' ], 'y' ) )->toBe( [ 1600.0, 1200.0 ] );
} );

it( 'caps the plotted history and says that it did', function (): void {
    $rows = TrendChart::MAX_RESULTS + 5;

    for ( $index = 0; $index < $rows; $index++ ) {
        trendResult( 0, [ 'performance_score' => 50 ] );
    }

    $component = Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', TrendChart::STATE_LOADED )
        ->assertSet( 'truncated', true )
        ->assertSet( 'pointCount', TrendChart::MAX_RESULTS )
        ->assertSee( 'Showing the most recent results only' );

    expect( $component->get( 'series' )[ 0 ][ 'data' ] )->toHaveCount( TrendChart::MAX_RESULTS );
} );

it( 'keeps the newest results when the cap bites, so the line still ends at today', function (): void {
    // Truncating from the other end stops the line months short, which reads
    // as testing having stopped rather than as a chart that was shortened.
    for ( $index = 0; $index < TrendChart::MAX_RESULTS; $index++ ) {
        trendResult( 300, [ 'performance_score' => 10 ] );
    }

    trendResult( 1, [ 'performance_score' => 99 ] );

    $series = Livewire::test( TrendChart::class, [
        'url'   => 'https://example.com/page',
        'range' => 365,
    ] )
        ->assertSet( 'truncated', true )
        ->get( 'series' );

    $plotted = array_column( $series[ 0 ][ 'data' ], 'y' );

    expect( end( $plotted ) )->toBe( 99 );
} );

it( 'does not claim truncation for a range that fits', function (): void {
    trendResult( 10, [ 'performance_score' => 90 ] );
    trendResult( 2, [ 'performance_score' => 92 ] );

    Livewire::test( TrendChart::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'truncated', false )
        ->assertDontSee( 'Showing the most recent results only' );
} );
