<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Data\FieldData;

/**
 * Build a field data set with the metric keys PageSpeed embeds.
 *
 * @param  array<string, array<string, mixed>>  $overrides  Extra or replacement metrics.
 *
 * @return FieldData The built set.
 */
function fieldDataWith( array $overrides = [] ): FieldData
{
    return new FieldData(
        metrics: array_merge( [
            'LARGEST_CONTENTFUL_PAINT_MS'   => [ 'percentile' => 1893, 'category' => 'FAST', 'distributions' => [] ],
            'INTERACTION_TO_NEXT_PAINT'     => [ 'percentile' => 112, 'category' => 'FAST', 'distributions' => [] ],
            'CUMULATIVE_LAYOUT_SHIFT_SCORE' => [ 'percentile' => 2, 'category' => 'FAST', 'distributions' => [] ],
        ], $overrides ),
        overallCategory: 'FAST',
        id: 'https://example.com/',
    );
}

it( 'reports the percentile as p75, per live keyed responses', function (): void {
    expect( FieldData::PERCENTILE )->toBe( 75 );
} );

it( 'reads Core Web Vitals from the upper-snake keys PageSpeed embeds', function (): void {
    $field = fieldDataWith();

    expect( $field->largestContentfulPaint()[ 'percentile' ] )->toBe( 1893 )
        ->and( $field->interactionToNextPaint()[ 'percentile' ] )->toBe( 112 )
        ->and( $field->cumulativeLayoutShift()[ 'category' ] )->toBe( 'FAST' );
} );

it( 'also reads the lower-snake keys the standalone CrUX API uses', function (): void {
    $field = new FieldData( [
        'largest_contentful_paint' => [ 'percentile' => 2410, 'category' => 'AVERAGE', 'distributions' => [] ],
    ] );

    expect( $field->largestContentfulPaint()[ 'percentile' ] )->toBe( 2410 );
} );

it( 'returns null for a metric CrUX had no data for', function (): void {
    $field = fieldDataWith();

    expect( $field->timeToFirstByte() )->toBeNull()
        ->and( $field->firstContentfulPaint() )->toBeNull()
        ->and( $field->percentile( 'first_contentful_paint' ) )->toBeNull()
        ->and( $field->category( 'first_contentful_paint' ) )->toBeNull();
} );

it( 'reads a metric by its raw response key', function (): void {
    $field = fieldDataWith( [
        'SOME_FUTURE_METRIC' => [ 'percentile' => 42, 'category' => 'AVERAGE', 'distributions' => [] ],
    ] );

    expect( $field->percentile( 'SOME_FUTURE_METRIC' ) )->toBe( 42 );
} );

it( 'reports the origin fallback flag and the level it came from', function (): void {
    $page = new FieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => [ 'percentile' => 1, 'category' => 'FAST', 'distributions' => [] ] ], originFallback: true );

    expect( $page->isOriginFallback() )->toBeTrue()
        ->and( $page->isOriginLevel() )->toBeFalse();

    $origin = new FieldData( [], isOriginLevel: true );

    expect( $origin->isOriginLevel() )->toBeTrue();
} );

it( 'serializes to a storage-ready array', function (): void {
    $array = fieldDataWith()->toArray();

    expect( $array )->toHaveKeys( [ 'id', 'overall_category', 'origin_fallback', 'origin_level', 'metrics' ] )
        ->and( $array[ 'overall_category' ] )->toBe( 'FAST' )
        ->and( $array[ 'metrics' ] )->toHaveKey( 'LARGEST_CONTENTFUL_PAINT_MS' );
} );
