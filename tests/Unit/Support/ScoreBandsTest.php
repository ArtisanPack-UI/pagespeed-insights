<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Support\ScoreBands;

it( 'bands a category score on Google\'s 0-49 / 50-89 / 90-100 scale', function ( int $score, string $band ): void {
    expect( ScoreBands::forScore( $score ) )->toBe( $band );
} )->with( [
    'floor'                     => [ 0, ScoreBands::POOR ],
    'top of poor'               => [ 49, ScoreBands::POOR ],
    'bottom of needs work'      => [ 50, ScoreBands::NEEDS_IMPROVEMENT ],
    'top of needs work'         => [ 89, ScoreBands::NEEDS_IMPROVEMENT ],
    'bottom of good'            => [ 90, ScoreBands::GOOD ],
    'ceiling'                   => [ 100, ScoreBands::GOOD ],
] );

it( 'does not band an unscored category', function (): void {
    expect( ScoreBands::forScore( null ) )->toBeNull();
    expect( ScoreBands::color( null ) )->toBeNull();
    expect( ScoreBands::label( null ) )->toBeNull();
} );

it( 'maps each band to a theme colour', function (): void {
    expect( ScoreBands::color( ScoreBands::GOOD ) )->toBe( 'success' );
    expect( ScoreBands::color( ScoreBands::NEEDS_IMPROVEMENT ) )->toBe( 'warning' );
    expect( ScoreBands::color( ScoreBands::POOR ) )->toBe( 'error' );
} );

it( 'returns no colour for a band it does not know', function (): void {
    expect( ScoreBands::color( 'excellent' ) )->toBeNull();
} );

it( 'bands a Core Web Vital the other way round, since lower is better', function ( string $metric, int $value, string $band ): void {
    expect( ScoreBands::forVital( $metric, $value ) )->toBe( $band );
} )->with( [
    'fast LCP'  => [ 'largest_contentful_paint', 1200, ScoreBands::GOOD ],
    'slow LCP'  => [ 'largest_contentful_paint', 6000, ScoreBands::POOR ],
    'fast INP'  => [ 'interaction_to_next_paint', 90, ScoreBands::GOOD ],
    'mid INP'   => [ 'interaction_to_next_paint', 300, ScoreBands::NEEDS_IMPROVEMENT ],
    'small CLS' => [ 'cumulative_layout_shift', 4, ScoreBands::GOOD ],
    'large CLS' => [ 'cumulative_layout_shift', 90, ScoreBands::POOR ],
] );

it( 'does not band a vital CrUX had no measurement for', function (): void {
    expect( ScoreBands::forVital( 'largest_contentful_paint', null ) )->toBeNull();
} );

it( 'does not band a metric it has no thresholds for', function (): void {
    expect( ScoreBands::forVital( 'first_contentful_paint', 1200 ) )->toBeNull();
} );
