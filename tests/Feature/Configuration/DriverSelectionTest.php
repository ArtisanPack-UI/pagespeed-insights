<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Configuration\CmsSettingsDriver;
use ArtisanPackUI\PageSpeedInsights\Configuration\ConfigDriver;
use ArtisanPackUI\PageSpeedInsights\Configuration\DatabaseDriver;
use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;

it( 'defaults to the config driver', function (): void {
    expect( config( 'pagespeed-insights.driver' ) )->toBe( 'config' );
    expect( app( ApiKeyRepository::class ) )->toBeInstanceOf( ConfigDriver::class );
} );

it( 'resolves the driver named in configuration', function ( string $driver, string $expected ): void {
    config()->set( 'pagespeed-insights.driver', $driver );

    expect( app( ApiKeyRepository::class ) )->toBeInstanceOf( $expected );
} )->with( [
    'config'   => [ 'config', ConfigDriver::class ],
    'database' => [ 'database', DatabaseDriver::class ],
    'cms'      => [ 'cms', CmsSettingsDriver::class ],
] );

it( 'falls back to the config driver for an unknown driver name', function (): void {
    config()->set( 'pagespeed-insights.driver', 'redis-but-imaginary' );

    expect( app( ApiKeyRepository::class ) )->toBeInstanceOf( ConfigDriver::class );
} );

it( 're-reads the configured driver on every resolve', function (): void {
    expect( app( ApiKeyRepository::class ) )->toBeInstanceOf( ConfigDriver::class );

    config()->set( 'pagespeed-insights.driver', 'database' );

    expect( app( ApiKeyRepository::class ) )->toBeInstanceOf( DatabaseDriver::class );
} );

it( 'keeps each concrete driver a singleton so its cache survives', function (): void {
    expect( app( ConfigDriver::class ) )->toBe( app( ConfigDriver::class ) );
    expect( app( DatabaseDriver::class ) )->toBe( app( DatabaseDriver::class ) );
    expect( app( CmsSettingsDriver::class ) )->toBe( app( CmsSettingsDriver::class ) );
} );

it( 'exposes the configured repository through the facade root', function (): void {
    config()->set( 'pagespeed-insights.driver', 'database' );

    expect( pageSpeedInsights()->config() )->toBeInstanceOf( DatabaseDriver::class );
} );
