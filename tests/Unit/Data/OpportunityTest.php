<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Data\Opportunity;

it( 'weighs an opportunity by its overall saving', function (): void {
    $opportunity = new Opportunity(
        id: 'render-blocking-resources',
        title: 'Eliminate render-blocking resources',
        score: 0.11,
        savingsMs: 1800.0,
        metricSavings: [ 'FCP' => 1600.0, 'LCP' => 1800.0 ],
    );

    expect( $opportunity->weight() )->toBe( 1800.0 );
} );

it( 'falls back to the largest per-metric saving when there is no overall one', function (): void {
    $opportunity = new Opportunity(
        id: 'modern-image-formats',
        title: 'Serve images in next-gen formats',
        metricSavings: [ 'FCP' => 40.0, 'LCP' => 90.0 ],
    );

    expect( $opportunity->largestMetricSaving() )->toBe( 90.0 )
        ->and( $opportunity->weight() )->toBe( 90.0 );
} );

it( 'takes the larger estimate when the two disagree', function (): void {
    $opportunity = new Opportunity(
        id: 'render-blocking-insight',
        title: 'Render blocking requests',
        score: 0.0,
        savingsMs: 0.0,
        metricSavings: [ 'FCP' => 1900.0, 'LCP' => 2100.0 ],
    );

    expect( $opportunity->weight() )->toBe( 2100.0 );
} );

it( 'weighs an opportunity with no savings at all as zero', function (): void {
    $opportunity = new Opportunity( id: 'some-audit', title: 'Some audit' );

    expect( $opportunity->largestMetricSaving() )->toBe( 0.0 )
        ->and( $opportunity->weight() )->toBe( 0.0 );
} );

it( 'serializes to a storage-ready array', function (): void {
    $array = ( new Opportunity(
        id: 'unused-javascript',
        title: 'Reduce unused JavaScript',
        score: 0.83,
        savingsMs: 320.0,
        displayValue: 'Potential savings of 42 KiB',
        metricSavings: [ 'LCP' => 300.0 ],
        description: 'Reduce unused JavaScript.',
    ) )->toArray();

    expect( $array[ 'id' ] )->toBe( 'unused-javascript' )
        ->and( $array[ 'savings_ms' ] )->toBe( 320.0 )
        ->and( $array[ 'metric_savings' ] )->toBe( [ 'LCP' => 300.0 ] )
        ->and( $array[ 'display_value' ] )->toBe( 'Potential savings of 42 KiB' );
} );
