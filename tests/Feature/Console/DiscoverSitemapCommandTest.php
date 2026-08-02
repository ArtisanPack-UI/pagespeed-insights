<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses( RefreshDatabase::class );

it( 'stores discovered URLs inactive by default', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [
            'https://example.com/about',
            'https://example.com/pricing',
        ] ) ),
    ] );

    $this->artisan( 'pagespeed:discover-sitemap', [ '--sitemap' => 'https://example.com/sitemap.xml' ] )
        ->expectsOutputToContain( 'https://example.com/about' )
        ->expectsOutputToContain( 'Found 2 URL(s): 2 added, 0 already monitored.' )
        ->assertSuccessful();

    expect( PageSpeedUrl::query()->count() )->toBe( 2 )
        ->and( PageSpeedUrl::query()->where( 'is_active', true )->count() )->toBe( 0 )
        ->and( PageSpeedUrl::query()->where( 'source', PageSpeedUrl::SOURCE_SITEMAP )->count() )->toBe( 2 );
} );

it( 'activates discovered URLs when asked to', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [ 'https://example.com/about' ] ) ),
    ] );

    $this->artisan( 'pagespeed:discover-sitemap', [
        '--sitemap'  => 'https://example.com/sitemap.xml',
        '--activate' => true,
    ] )->assertSuccessful();

    expect( PageSpeedUrl::query()->where( 'is_active', true )->count() )->toBe( 1 );
} );

it( 'does not duplicate URLs that are already monitored', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/about' ] );

    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [
            'https://example.com/about/',
            'https://example.com/pricing',
        ] ) ),
    ] );

    $this->artisan( 'pagespeed:discover-sitemap', [ '--sitemap' => 'https://example.com/sitemap.xml' ] )
        ->expectsOutputToContain( 'Found 2 URL(s): 1 added, 1 already monitored.' )
        ->assertSuccessful();

    expect( PageSpeedUrl::query()->count() )->toBe( 2 );
} );

it( 'honours the limit option', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [
            'https://example.com/one',
            'https://example.com/two',
            'https://example.com/three',
        ] ) ),
    ] );

    $this->artisan( 'pagespeed:discover-sitemap', [
        '--sitemap' => 'https://example.com/sitemap.xml',
        '--limit'   => '2',
    ] )->assertSuccessful();

    expect( PageSpeedUrl::query()->count() )->toBe( 2 );
} );

it( 'rejects a limit that is not a positive whole number', function (): void {
    $this->artisan( 'pagespeed:discover-sitemap', [
        '--sitemap' => 'https://example.com/sitemap.xml',
        '--limit'   => '0',
    ] )->expectsOutputToContain( 'positive whole number' )->assertFailed();

    expect( PageSpeedUrl::query()->count() )->toBe( 0 );
} );

it( 'reports a sitemap it could not read', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( 'Not found', 404 ),
    ] );

    $this->artisan( 'pagespeed:discover-sitemap', [ '--sitemap' => 'https://example.com/sitemap.xml' ] )
        ->expectsOutputToContain( 'HTTP 404' )
        ->assertFailed();
} );

it( 'reports an empty sitemap without failing', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [] ) ),
    ] );

    $this->artisan( 'pagespeed:discover-sitemap', [ '--sitemap' => 'https://example.com/sitemap.xml' ] )
        ->expectsOutputToContain( 'No URLs were found' )
        ->assertSuccessful();
} );

it( 'falls back to the configured sitemap when none is named', function (): void {
    config()->set( 'pagespeed-insights.sitemap.url', 'https://example.com/sitemap_index.xml' );

    Http::fake( [
        'https://example.com/sitemap_index.xml' => Http::response( psiSitemapUrlset( [ 'https://example.com/about' ] ) ),
    ] );

    $this->artisan( 'pagespeed:discover-sitemap' )
        ->expectsOutputToContain( 'https://example.com/sitemap_index.xml' )
        ->assertSuccessful();

    expect( PageSpeedUrl::query()->count() )->toBe( 1 );
} );
