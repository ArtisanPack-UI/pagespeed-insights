<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Data\FieldData;
use ArtisanPackUI\PageSpeedInsights\Livewire\CoreWebVitalsCard;
use ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Support\UiComponentsInstalled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses( RefreshDatabase::class );

/**
 * Build a stored field data payload with the given metric percentiles.
 *
 * @param  array<string, int>  $percentiles  Raw CrUX key to percentile value.
 * @param  bool  $originLevel  Whether this is the origin-level set.
 * @param  bool  $originFallback  Whether CrUX substituted origin data.
 *
 * @return array<string, mixed> The stored shape.
 */
function psiStoredFieldData(
    array $percentiles,
    bool $originLevel = false,
    bool $originFallback = false,
): array {
    $metrics = [];

    foreach ( $percentiles as $key => $percentile ) {
        $metrics[ $key ] = [
            'percentile'    => $percentile,
            'category'      => 'FAST',
            'distributions' => [],
        ];
    }

    return [
        'id'               => $originLevel ? 'https://example.com' : 'https://example.com/page',
        'overall_category' => 'FAST',
        'origin_fallback'  => $originFallback,
        'origin_level'     => $originLevel,
        'metrics'          => $metrics,
    ];
}

it( 'renders the empty state when nothing has been stored', function (): void {
    Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', CoreWebVitalsCard::STATE_EMPTY )
        ->assertSee( 'No PageSpeed test has run for this URL yet.' )
        ->assertDontSee( 'Not enough field data' );
} );

it( 'renders the error state with the stored failure message', function (): void {
    PageSpeedResult::factory()->failed( 'PageSpeed returned HTTP 500 for this URL.' )->create( [
        'url'      => 'https://example.com/page',
        'strategy' => 'mobile',
    ] );

    Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', CoreWebVitalsCard::STATE_FAILED )
        ->assertSee( 'The last PageSpeed run failed' )
        ->assertSee( 'PageSpeed returned HTTP 500 for this URL.' )
        ->assertDontSee( 'Not enough field data' );
} );

it( 'renders the not-enough-field-data state when CrUX had nothing for the page or the origin', function (): void {
    PageSpeedResult::factory()->withoutFieldData()->create( [
        'url'      => 'https://example.com/page',
        'strategy' => 'mobile',
    ] );

    Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', CoreWebVitalsCard::STATE_NO_FIELD_DATA )
        ->assertSet( 'originLevel', false )
        ->assertSee( 'Not enough field data' )
        ->assertDontSee( 'Showing site-wide data' )
        ->assertDontSee( 'No PageSpeed test has run for this URL yet.' );
} );

it( 'treats a field data shell carrying no metrics as no field data', function (): void {
    PageSpeedResult::factory()->create( [
        'url'               => 'https://example.com/page',
        'strategy'          => 'mobile',
        'field_data'        => psiStoredFieldData( [] ),
        'origin_field_data' => null,
    ] );

    Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', CoreWebVitalsCard::STATE_NO_FIELD_DATA );
} );

it( 'renders page-level vitals with the right bands', function (): void {
    PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/page',
        'strategy'   => 'mobile',
        'field_data' => psiStoredFieldData( [
            'LARGEST_CONTENTFUL_PAINT_MS'   => 1900,
            'INTERACTION_TO_NEXT_PAINT'     => 350,
            'CUMULATIVE_LAYOUT_SHIFT_SCORE' => 40,
        ] ),
    ] );

    $component = Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', CoreWebVitalsCard::STATE_LOADED )
        ->assertSet( 'originLevel', false )
        ->assertSee( 'LCP' )
        ->assertSee( 'INP' )
        ->assertSee( 'CLS' )
        ->assertDontSee( 'Showing site-wide data' )
        ->assertDontSee( 'Not enough field data' );

    $vitals = collect( $component->get( 'vitals' ) )->keyBy( 'metric' );

    expect( $vitals[ 'largest_contentful_paint' ][ 'band' ] )->toBe( 'good' );
    expect( $vitals[ 'largest_contentful_paint' ][ 'display' ] )->toBe( '1.9 s' );

    expect( $vitals[ 'interaction_to_next_paint' ][ 'band' ] )->toBe( 'needs-improvement' );
    expect( $vitals[ 'interaction_to_next_paint' ][ 'display' ] )->toBe( '350 ms' );

    expect( $vitals[ 'cumulative_layout_shift' ][ 'band' ] )->toBe( 'poor' );
    expect( $vitals[ 'cumulative_layout_shift' ][ 'display' ] )->toBe( '0.40' );
} );

it( 'bands each vital against its own thresholds', function ( string $key, string $metric, int $value, string $band ): void {
    PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/page',
        'strategy'   => 'mobile',
        'field_data' => psiStoredFieldData( [ $key => $value ] ),
    ] );

    $vitals = collect(
        Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )->get( 'vitals' ),
    )->keyBy( 'metric' );

    expect( $vitals[ $metric ][ 'band' ] )->toBe( $band );
} )->with( [
    'LCP at the good boundary'    => [ 'LARGEST_CONTENTFUL_PAINT_MS', 'largest_contentful_paint', 2500, 'good' ],
    'LCP just past good'          => [ 'LARGEST_CONTENTFUL_PAINT_MS', 'largest_contentful_paint', 2501, 'needs-improvement' ],
    'LCP past the upper bound'    => [ 'LARGEST_CONTENTFUL_PAINT_MS', 'largest_contentful_paint', 4001, 'poor' ],
    'INP at the good boundary'    => [ 'INTERACTION_TO_NEXT_PAINT', 'interaction_to_next_paint', 200, 'good' ],
    'INP past the upper bound'    => [ 'INTERACTION_TO_NEXT_PAINT', 'interaction_to_next_paint', 501, 'poor' ],
    'CLS at the good boundary'    => [ 'CUMULATIVE_LAYOUT_SHIFT_SCORE', 'cumulative_layout_shift', 10, 'good' ],
    'CLS in the middle band'      => [ 'CUMULATIVE_LAYOUT_SHIFT_SCORE', 'cumulative_layout_shift', 25, 'needs-improvement' ],
    'CLS past the upper bound'    => [ 'CUMULATIVE_LAYOUT_SHIFT_SCORE', 'cumulative_layout_shift', 26, 'poor' ],
] );

it( 'renders a vital CrUX had no measurement for as unavailable rather than as a zero', function (): void {
    PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/page',
        'strategy'   => 'mobile',
        'field_data' => psiStoredFieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => 1900 ] ),
    ] );

    $component = Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSee( 'No data' );

    $vitals = collect( $component->get( 'vitals' ) )->keyBy( 'metric' );

    expect( $vitals[ 'interaction_to_next_paint' ][ 'available' ] )->toBeFalse();
    expect( $vitals[ 'interaction_to_next_paint' ][ 'value' ] )->toBeNull();
    expect( $vitals[ 'interaction_to_next_paint' ][ 'band' ] )->toBeNull();
    expect( $vitals[ 'interaction_to_next_paint' ][ 'display' ] )->toBeNull();
} );

it( 'labels origin-level data as site-wide, worded apart from the empty state', function (): void {
    PageSpeedResult::factory()->create( [
        'url'               => 'https://example.com/page',
        'strategy'          => 'mobile',
        'field_data'        => null,
        'origin_field_data' => psiStoredFieldData(
            [ 'LARGEST_CONTENTFUL_PAINT_MS' => 3200 ],
            originLevel: true,
        ),
    ] );

    Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', CoreWebVitalsCard::STATE_ORIGIN_LEVEL )
        ->assertSet( 'originLevel', true )
        ->assertSet( 'dataSubject', 'https://example.com' )
        ->assertSee( 'Showing site-wide data' )
        ->assertSee( 'https://example.com' )
        ->assertDontSee( 'Not enough field data' );
} );

it( 'labels a CrUX origin fallback on page-level data as site-wide too', function (): void {
    PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/page',
        'strategy'   => 'mobile',
        'field_data' => psiStoredFieldData(
            [ 'LARGEST_CONTENTFUL_PAINT_MS' => 3200 ],
            originFallback: true,
        ),
    ] );

    Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', CoreWebVitalsCard::STATE_ORIGIN_LEVEL )
        ->assertSet( 'originLevel', true )
        ->assertSee( 'Showing site-wide data' );
} );

it( 'prefers page-level data over the origin-level set', function (): void {
    PageSpeedResult::factory()->create( [
        'url'               => 'https://example.com/page',
        'strategy'          => 'mobile',
        'field_data'        => psiStoredFieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => 1200 ] ),
        'origin_field_data' => psiStoredFieldData(
            [ 'LARGEST_CONTENTFUL_PAINT_MS' => 5600 ],
            originLevel: true,
        ),
    ] );

    $component = Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', CoreWebVitalsCard::STATE_LOADED )
        ->assertSet( 'originLevel', false );

    $vitals = collect( $component->get( 'vitals' ) )->keyBy( 'metric' );

    expect( $vitals[ 'largest_contentful_paint' ][ 'value' ] )->toBe( 1200 );
} );

it( 'labels the percentile from the parser rather than from a hard-coded view string', function (): void {
    PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/page',
        'strategy'   => 'mobile',
        'field_data' => psiStoredFieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => 1900 ] ),
    ] );

    Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'percentile', FieldData::PERCENTILE )
        ->assertSee( (string) FieldData::PERCENTILE );
} );

it( 'reads the run for the requested form factor', function (): void {
    PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/page',
        'strategy'   => 'mobile',
        'field_data' => psiStoredFieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => 4800 ] ),
    ] );

    PageSpeedResult::factory()->desktop()->create( [
        'url'        => 'https://example.com/page',
        'field_data' => psiStoredFieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => 1100 ] ),
    ] );

    $vitals = collect(
        Livewire::test( CoreWebVitalsCard::class, [
            'url'      => 'https://example.com/page',
            'strategy' => 'desktop',
        ] )->get( 'vitals' ),
    )->keyBy( 'metric' );

    expect( $vitals[ 'largest_contentful_paint' ][ 'value' ] )->toBe( 1100 );
} );

it( 'picks up a newer run when it is refreshed', function (): void {
    PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/page',
        'strategy'   => 'mobile',
        'field_data' => psiStoredFieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => 4800 ] ),
    ] );

    $component = Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] );

    PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/page',
        'strategy'   => 'mobile',
        'field_data' => psiStoredFieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => 1100 ] ),
    ] );

    $component->call( 'refresh' );

    $vitals = collect( $component->get( 'vitals' ) )->keyBy( 'metric' );

    expect( $vitals[ 'largest_contentful_paint' ][ 'value' ] )->toBe( 1100 );
} );

it( 'catches up when the score card announces a finished run', function (): void {
    $component = Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', CoreWebVitalsCard::STATE_EMPTY )
        ->assertSee( 'No PageSpeed test has run for this URL yet.' );

    PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/page',
        'strategy'   => 'mobile',
        'field_data' => psiStoredFieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => 1400 ] ),
    ] );

    $component->dispatch(
        ScoreCard::EVENT_RESULT_STORED,
        url: 'https://example.com/page',
        strategy: 'mobile',
    )
        ->assertSet( 'state', CoreWebVitalsCard::STATE_LOADED )
        ->assertSee( 'LCP' )
        ->assertDontSee( 'No PageSpeed test has run for this URL yet.' );
} );

it( 'ignores a run announced for a different URL', function (): void {
    $component = Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] );

    PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/page',
        'strategy'   => 'mobile',
        'field_data' => psiStoredFieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => 1400 ] ),
    ] );

    $component->dispatch(
        ScoreCard::EVENT_RESULT_STORED,
        url: 'https://example.com/other',
        strategy: 'mobile',
    )->assertSet( 'state', CoreWebVitalsCard::STATE_EMPTY );
} );

it( 'ignores a run announced for a different form factor', function (): void {
    $component = Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] );

    PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/page',
        'strategy'   => 'mobile',
        'field_data' => psiStoredFieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => 1400 ] ),
    ] );

    $component->dispatch(
        ScoreCard::EVENT_RESULT_STORED,
        url: 'https://example.com/page',
        strategy: 'desktop',
    )->assertSet( 'state', CoreWebVitalsCard::STATE_EMPTY );
} );

it( 'renders an install notice instead of exploding when the component library is absent', function (): void {
    UiComponentsInstalled::setForTesting( false );

    Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'uiComponentsInstalled', false )
        ->assertSee( 'composer require artisanpack-ui/livewire-ui-components' );
} );

it( 'refuses a URL the browser asks it to change', function (): void {
    Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->set( 'url', 'https://attacker.example/pwn' );
} )->throws( CannotUpdateLockedPropertyException::class );

it( 'refuses a form factor the browser asks it to change', function (): void {
    Livewire::test( CoreWebVitalsCard::class, [ 'url' => 'https://example.com/page' ] )
        ->set( 'strategy', 'desktop' );
} )->throws( CannotUpdateLockedPropertyException::class );
