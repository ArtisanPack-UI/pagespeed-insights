<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Configuration\ConfigDriver;
use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;

it( 'reads the API key from the config repository', function (): void {
    config()->set( 'pagespeed-insights.api_key', 'psi-test-key' );

    $driver = app( ApiKeyRepository::class );

    expect( $driver )->toBeInstanceOf( ConfigDriver::class );
    expect( $driver->getApiKey() )->toBe( 'psi-test-key' );
    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'trims surrounding whitespace from the configured key', function (): void {
    config()->set( 'pagespeed-insights.api_key', "  psi-test-key\n" );

    expect( app( ApiKeyRepository::class )->getApiKey() )->toBe( 'psi-test-key' );
} );

it( 'reports unconfigured when no key is set', function ( mixed $value ): void {
    config()->set( 'pagespeed-insights.api_key', $value );

    $driver = app( ApiKeyRepository::class );

    expect( $driver->getApiKey() )->toBeNull();
    expect( $driver->isConfigured() )->toBeFalse();
} )->with( [
    'null'         => [ null ],
    'empty string' => [ '' ],
    'whitespace'   => [ '   ' ],
    'non-string'   => [ 12345 ],
] );

it( 'throws when save is called on the read-only config driver', function (): void {
    $driver = app( ApiKeyRepository::class );

    expect( fn () => $driver->save( 'psi-test-key' ) )
        ->toThrow( RuntimeException::class );
} );

it( 'names the alternative drivers in the read-only save error', function (): void {
    $driver = app( ApiKeyRepository::class );

    expect( fn () => $driver->save( 'psi-test-key' ) )
        ->toThrow( RuntimeException::class, 'PAGESPEED_CONFIG_DRIVER' );
} );
