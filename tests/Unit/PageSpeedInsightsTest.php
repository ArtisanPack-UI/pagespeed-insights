<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\PageSpeedInsights;

it( 'exposes a semantic version', function (): void {
    expect( PageSpeedInsights::VERSION )->toBeSemver();
} );

it( 'returns the version constant from the version method', function (): void {
    expect( ( new PageSpeedInsights() )->version() )->toBe( PageSpeedInsights::VERSION );
} );

it( 'keeps the version constant in step with composer.json', function (): void {
    $composer = json_decode(
        (string) file_get_contents( __DIR__ . '/../../composer.json' ),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect( $composer[ 'version' ] )->toBe( PageSpeedInsights::VERSION );
} );
