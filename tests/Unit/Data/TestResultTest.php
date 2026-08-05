<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Data\FieldData;
use ArtisanPackUI\PageSpeedInsights\Data\LabMetrics;
use ArtisanPackUI\PageSpeedInsights\Data\ScoreSet;
use ArtisanPackUI\PageSpeedInsights\Data\TestResult;

/**
 * Build a minimal result with whatever the test cares about.
 *
 * @param  array<string, mixed>  $overrides  Constructor overrides.
 *
 * @return TestResult The built result.
 */
function testResult( array $overrides = [] ): TestResult
{
    return new TestResult( ...array_merge( [
        'url'        => 'https://example.com/',
        'strategy'   => 'mobile',
        'scores'     => new ScoreSet( [ 'performance' => 97 ] ),
        'labMetrics' => new LabMetrics(),
    ], $overrides ) );
}

it( 'prefers page-level field data over origin-level', function (): void {
    $page   = new FieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => [ 'percentile' => 1, 'category' => 'FAST', 'distributions' => [] ] ] );
    $origin = new FieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => [ 'percentile' => 2, 'category' => 'FAST', 'distributions' => [] ] ], isOriginLevel: true );

    $result = testResult( [ 'fieldData' => $page, 'originFieldData' => $origin ] );

    expect( $result->hasFieldData() )->toBeTrue()
        ->and( $result->effectiveFieldData() )->toBe( $page );
} );

it( 'falls back to origin-level field data when there is no page-level data', function (): void {
    $origin = new FieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => [ 'percentile' => 2, 'category' => 'FAST', 'distributions' => [] ] ], isOriginLevel: true );

    $result = testResult( [ 'originFieldData' => $origin ] );

    expect( $result->effectiveFieldData() )->toBe( $origin );
} );

it( 'reports no field data when CrUX had none at either level', function (): void {
    $result = testResult();

    expect( $result->hasFieldData() )->toBeFalse()
        ->and( $result->effectiveFieldData() )->toBeNull()
        ->and( $result->warnings()[ 'missing_field_data' ] )->toBeTrue();
} );

it( 'carries everything the parser tolerated so a thin result can be explained', function (): void {
    $result = testResult( [
        'runWarnings'            => [ 'A resource load ended in an error.' ],
        'unrecognizedCategories' => [ 'agentic-browsing' ],
        'missingCategories'      => [ 'seo' ],
        'missingMetrics'         => [ 'speed-index' ],
    ] );

    expect( $result->hasWarnings() )->toBeTrue()
        ->and( $result->warnings() )->toBe( [
            'run_warnings'            => [ 'A resource load ended in an error.' ],
            'unrecognized_categories' => [ 'agentic-browsing' ],
            'missing_categories'      => [ 'seo' ],
            'missing_metrics'         => [ 'speed-index' ],
            'missing_field_data'      => true,
        ] );
} );

it( 'reports a clean run with field data as having nothing to explain', function (): void {
    $result = testResult( [
        'fieldData' => new FieldData( [ 'LARGEST_CONTENTFUL_PAINT_MS' => [ 'percentile' => 1, 'category' => 'FAST', 'distributions' => [] ] ] ),
    ] );

    expect( $result->hasWarnings() )->toBeFalse();
} );

it( 'serializes to a storage-ready array', function (): void {
    $array = testResult()->toArray();

    expect( $array )->toHaveKeys( [
        'url',
        'strategy',
        'final_url',
        'lighthouse_version',
        'analyzed_at',
        'scores',
        'lab_metrics',
        'field_data',
        'origin_field_data',
        'opportunities',
        'warnings',
    ] )->and( $array[ 'scores' ] )->toBe( [ 'performance' => 97 ] );
} );
