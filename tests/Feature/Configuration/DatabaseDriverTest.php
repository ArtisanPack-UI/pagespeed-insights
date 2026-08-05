<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Configuration\DatabaseDriver;
use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'pagespeed-insights.driver', 'database' );
} );

it( 'creates the pagespeed_configurations table from the package migration', function (): void {
    expect( Schema::hasTable( 'pagespeed_configurations' ) )->toBeTrue();
    expect( Schema::hasColumns( 'pagespeed_configurations', [ 'id', 'api_key', 'created_at', 'updated_at' ] ) )
        ->toBeTrue();
} );

it( 'reports unconfigured when no row exists', function (): void {
    $driver = app( ApiKeyRepository::class );

    expect( $driver )->toBeInstanceOf( DatabaseDriver::class );
    expect( $driver->getApiKey() )->toBeNull();
    expect( $driver->isConfigured() )->toBeFalse();
} );

it( 'persists and reads the key back', function (): void {
    $driver = app( ApiKeyRepository::class );

    $driver->save( 'psi-database-key' );

    expect( $driver->getApiKey() )->toBe( 'psi-database-key' );
    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'stores the key encrypted at rest', function (): void {
    app( ApiKeyRepository::class )->save( 'psi-database-key' );

    $stored = DB::table( 'pagespeed_configurations' )->first();

    expect( $stored->api_key )->not->toBe( 'psi-database-key' );
    expect( app( 'encrypter' )->decryptString( $stored->api_key ) )->toBe( 'psi-database-key' );
} );

it( 'updates the existing row on subsequent saves', function (): void {
    $driver = app( ApiKeyRepository::class );

    $driver->save( 'first-key' );
    $driver->save( 'second-key' );

    expect( DB::table( 'pagespeed_configurations' )->count() )->toBe( 1 );
    expect( $driver->getApiKey() )->toBe( 'second-key' );
} );

it( 'clears the stored key when saving null or a blank string', function ( ?string $value ): void {
    $driver = app( ApiKeyRepository::class );

    $driver->save( 'psi-database-key' );
    $driver->save( $value );

    expect( DB::table( 'pagespeed_configurations' )->first()->api_key )->toBeNull();
    expect( $driver->getApiKey() )->toBeNull();
    expect( $driver->isConfigured() )->toBeFalse();
} )->with( [
    'null'         => [ null ],
    'empty string' => [ '' ],
    'whitespace'   => [ '   ' ],
] );

it( 'trims the key before encrypting it', function (): void {
    $driver = app( ApiKeyRepository::class );

    $driver->save( "  psi-database-key\n" );

    expect( $driver->getApiKey() )->toBe( 'psi-database-key' );
} );

it( 'caches the decrypted key for the request', function (): void {
    $driver = app( ApiKeyRepository::class );
    $driver->save( 'psi-database-key' );

    // Prime the cache, then change the row underneath the driver; the cached
    // value should win until the cache is flushed.
    expect( $driver->getApiKey() )->toBe( 'psi-database-key' );

    DB::table( 'pagespeed_configurations' )
        ->update( [ 'api_key' => app( 'encrypter' )->encryptString( 'rotated-key' ) ] );

    expect( $driver->getApiKey() )->toBe( 'psi-database-key' );

    $driver->flush();

    expect( $driver->getApiKey() )->toBe( 'rotated-key' );
} );

it( 'treats a missing table as unconfigured and names the migration in the log', function (): void {
    Log::spy();

    Schema::drop( 'pagespeed_configurations' );

    $driver = app( ApiKeyRepository::class );

    expect( $driver->getApiKey() )->toBeNull();
    expect( $driver->isConfigured() )->toBeFalse();

    Log::shouldHaveReceived( 'warning' )
        ->once()
        ->withArgs( fn ( mixed ...$args ): bool => str_contains( (string) $args[ 0 ], 'artisan migrate' ) );
} );

it( 'treats an undecryptable key as unconfigured and logs a warning', function (): void {
    Log::spy();

    DB::table( 'pagespeed_configurations' )->insert( [
        'api_key'    => 'not-valid-ciphertext',
        'created_at' => now(),
        'updated_at' => now(),
    ] );

    $driver = app( ApiKeyRepository::class );

    expect( $driver->getApiKey() )->toBeNull();
    expect( $driver->isConfigured() )->toBeFalse();

    Log::shouldHaveReceived( 'warning' )
        ->once()
        ->withArgs( fn ( mixed ...$args ): bool => str_contains( (string) $args[ 0 ], 'failed to decrypt' ) );
} );
