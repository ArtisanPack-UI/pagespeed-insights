<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Data\LabMetrics;

it( 'exposes the five metrics that make up the performance score', function (): void {
    $metrics = new LabMetrics( [
        'first-contentful-paint'   => [ 'value' => 1123.44, 'display' => '1.1 s' ],
        'largest-contentful-paint' => [ 'value' => 1834.21, 'display' => '1.8 s' ],
        'total-blocking-time'      => [ 'value' => 45.0, 'display' => '50 ms' ],
        'cumulative-layout-shift'  => [ 'value' => 0.012, 'display' => '0.012' ],
        'speed-index'              => [ 'value' => 1502.91, 'display' => '1.5 s' ],
    ] );

    expect( $metrics->firstContentfulPaint() )->toBe( 1123.44 )
        ->and( $metrics->largestContentfulPaint() )->toBe( 1834.21 )
        ->and( $metrics->totalBlockingTime() )->toBe( 45.0 )
        ->and( $metrics->cumulativeLayoutShift() )->toBe( 0.012 )
        ->and( $metrics->speedIndex() )->toBe( 1502.91 )
        ->and( $metrics->display( 'speed-index' ) )->toBe( '1.5 s' );
} );

it( 'does not carry Time to Interactive, removed in Lighthouse 10', function (): void {
    expect( LabMetrics::AUDIT_IDS )->not->toContain( 'interactive' );
} );

it( 'returns null and lists the audit id when a metric is absent', function (): void {
    $metrics = new LabMetrics(
        [ 'first-contentful-paint' => [ 'value' => 1900.0, 'display' => '1.9 s' ] ],
        [ 'speed-index' ],
    );

    expect( $metrics->speedIndex() )->toBeNull()
        ->and( $metrics->display( 'speed-index' ) )->toBeNull()
        ->and( $metrics->missing() )->toBe( [ 'speed-index' ] );
} );
