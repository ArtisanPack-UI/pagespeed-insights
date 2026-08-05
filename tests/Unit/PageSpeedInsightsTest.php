<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\PageSpeedInsights;

it( 'exposes a semantic version', function (): void {
    expect( PageSpeedInsights::VERSION )->toBeSemver();
} );

it( 'returns the version constant from the version method', function (): void {
    expect( ( new PageSpeedInsights() )->version() )->toBe( PageSpeedInsights::VERSION );
} );

it( 'leaves the version out of composer.json, where the git tag is authoritative', function (): void {
    // Packagist reads the tag. A hardcoded `version` here is a second place
    // for the number to live, and the one that goes stale: once 1.0.1 is
    // tagged, a field still reading 1.0.0 makes installs report a version
    // nobody shipped. The class constant stays, because a running application
    // has no tag to read.
    $composer = json_decode(
        (string) file_get_contents( __DIR__ . '/../../composer.json' ),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect( $composer )->not->toHaveKey( 'version' );
} );
