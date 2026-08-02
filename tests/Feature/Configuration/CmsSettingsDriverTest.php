<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Configuration\CmsSettingsDriver;
use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;

beforeEach( function (): void {
    // Reset the stub-backed CMS settings store between tests. The sanitize
    // callback was registered when the service provider booted via
    // `$this->app->booted()` (see tests/Support/CmsSettingsStub.php).
    $GLOBALS[ '__cms_settings_stub_values' ] = [];

    config()->set( 'pagespeed-insights.driver', 'cms' );
    app( CmsSettingsDriver::class )->flush();
} );

it( 'registers the API key setting with the CMS framework at boot', function (): void {
    expect( $GLOBALS[ '__cms_settings_stub_callbacks' ] )
        ->toHaveKey( CmsSettingsDriver::KEY_API_KEY );
} );

it( 'reports unconfigured when nothing is stored', function (): void {
    $driver = app( ApiKeyRepository::class );

    expect( $driver )->toBeInstanceOf( CmsSettingsDriver::class );
    expect( $driver->getApiKey() )->toBeNull();
    expect( $driver->isConfigured() )->toBeFalse();
} );

it( 'writes the key through apUpdateSetting and reads it back', function (): void {
    $driver = app( ApiKeyRepository::class );

    $driver->save( 'psi-cms-key' );

    expect( $driver->getApiKey() )->toBe( 'psi-cms-key' );
    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'stores the key encrypted at rest', function (): void {
    app( ApiKeyRepository::class )->save( 'psi-cms-key' );

    $raw = $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_API_KEY ];

    expect( $raw )->not->toBe( 'psi-cms-key' );
    expect( app( 'encrypter' )->decryptString( $raw ) )->toBe( 'psi-cms-key' );
} );

it( 'encrypts keys written directly through apUpdateSetting (Settings UI path)', function (): void {
    // Simulates an operator typing the API key into the CMS Settings admin
    // UI: apUpdateSetting is called with plaintext. The sanitize callback
    // registered by the service provider must encrypt it, otherwise the
    // driver's decryption step fails and isConfigured() flips to false with
    // no visible reason.
    apUpdateSetting( CmsSettingsDriver::KEY_API_KEY, 'ui-typed-key' );

    $raw = $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_API_KEY ];
    expect( $raw )->not->toBe( 'ui-typed-key' );

    /** @var CmsSettingsDriver $driver */
    $driver = app( ApiKeyRepository::class );
    $driver->flush();

    expect( $driver->getApiKey() )->toBe( 'ui-typed-key' );
    expect( $driver->isConfigured() )->toBeTrue();
} );

it( 'clears the stored key when saving null or a blank string', function ( ?string $value ): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( ApiKeyRepository::class );

    $driver->save( 'psi-cms-key' );
    $driver->save( $value );

    expect( $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_API_KEY ] )->toBeNull();
    expect( $driver->getApiKey() )->toBeNull();
    expect( $driver->isConfigured() )->toBeFalse();
} )->with( [
    'null'         => [ null ],
    'empty string' => [ '' ],
    'whitespace'   => [ '   ' ],
] );

it( 'trims the key before encrypting it', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( ApiKeyRepository::class );

    $driver->save( "  psi-cms-key\n" );

    expect( $driver->getApiKey() )->toBe( 'psi-cms-key' );
} );

it( 'caches the decrypted key until flushed', function (): void {
    /** @var CmsSettingsDriver $driver */
    $driver = app( ApiKeyRepository::class );
    $driver->save( 'psi-cms-key' );

    expect( $driver->getApiKey() )->toBe( 'psi-cms-key' );

    $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_API_KEY ] =
        app( 'encrypter' )->encryptString( 'rotated-key' );

    expect( $driver->getApiKey() )->toBe( 'psi-cms-key' );

    $driver->flush();

    expect( $driver->getApiKey() )->toBe( 'rotated-key' );
} );

it( 'treats an undecryptable key as unconfigured', function (): void {
    $GLOBALS[ '__cms_settings_stub_values' ][ CmsSettingsDriver::KEY_API_KEY ] = 'not-valid-ciphertext';

    /** @var CmsSettingsDriver $driver */
    $driver = app( ApiKeyRepository::class );
    $driver->flush();

    expect( $driver->getApiKey() )->toBeNull();
    expect( $driver->isConfigured() )->toBeFalse();
} );
