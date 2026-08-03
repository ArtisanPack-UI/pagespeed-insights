<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Http\Controllers\Controller;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\MonitoredUrlController;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HttpUser;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'app.url', 'https://example.com' );

    $this->actingAs( new HttpUser() );
} );

/*
|--------------------------------------------------------------------------
| Listing
|--------------------------------------------------------------------------
*/

it( 'lists the monitored set', function (): void {
    PageSpeedUrl::factory()->create( [
        'url'            => 'https://example.com/page',
        'label'          => 'Landing page',
        'test_frequency' => 'daily',
    ] );

    $response = $this->getJson( '/pagespeed/urls' )
        ->assertOk()
        ->assertJsonPath( 'total', 1 )
        ->assertJsonPath( 'truncated', false )
        ->assertJsonPath( 'urls.0.url', 'https://example.com/page' )
        ->assertJsonPath( 'urls.0.label', 'Landing page' )
        ->assertJsonPath( 'urls.0.frequency', 'daily' )
        ->assertJsonPath( 'urls.0.editable', true );

    expect( $response->json( 'urls.0.strategies' ) )->toBe( [ 'mobile', 'desktop' ] );
} );

it( 'lists hook-contributed URLs as uneditable', function (): void {
    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => array_merge(
        $urls,
        [ 'https://example.com/hooked' ],
    ) );

    $this->getJson( '/pagespeed/urls' )
        ->assertOk()
        ->assertJsonPath( 'total', 1 )
        ->assertJsonPath( 'urls.0.id', null )
        ->assertJsonPath( 'urls.0.editable', false )
        ->assertJsonPath( 'urls.0.source', PageSpeedUrl::SOURCE_HOOK );
} );

/*
|--------------------------------------------------------------------------
| Adding
|--------------------------------------------------------------------------
*/

it( 'adds a URL on this application\'s own origin', function (): void {
    $this->postJson( '/pagespeed/urls', [
        'url'           => 'https://example.com/about/',
        'label'         => 'About',
        'strategies'    => [ 'mobile' ],
        'testFrequency' => 'weekly',
        'isActive'      => false,
    ] )
        ->assertCreated()
        // Stored in canonical form, not as it was written.
        ->assertJsonPath( 'url', 'https://example.com/about' )
        ->assertJsonPath( 'label', 'About' )
        ->assertJsonPath( 'strategies', [ 'mobile' ] )
        ->assertJsonPath( 'testFrequency', 'weekly' )
        ->assertJsonPath( 'isActive', false );

    expect( PageSpeedUrl::query()->forUrl( 'https://example.com/about' )->exists() )->toBeTrue();
} );

it( 'refuses a URL outside this application\'s own site', function (): void {
    $this->postJson( '/pagespeed/urls', [ 'url' => 'https://competitor.example/pricing' ] )
        ->assertForbidden()
        ->assertJsonPath( 'error', Controller::ERROR_URL_NOT_ALLOWED );

    expect( PageSpeedUrl::query()->count() )->toBe( 0 );
} );

it( 'accepts an external URL when allow_external_urls is on', function (): void {
    config()->set( 'pagespeed-insights.routes.allow_external_urls', true );

    $this->postJson( '/pagespeed/urls', [ 'url' => 'https://competitor.example/pricing' ] )
        ->assertCreated()
        ->assertJsonPath( 'url', 'https://competitor.example/pricing' );
} );

it( 'accepts a URL that is already monitored but not stored, taking it over', function (): void {
    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => array_merge(
        $urls,
        [ 'https://elsewhere.example/hooked' ],
    ) );

    $this->postJson( '/pagespeed/urls', [ 'url' => 'https://elsewhere.example/hooked' ] )
        ->assertCreated()
        ->assertJsonPath( 'editable', true );
} );

it( 'refuses a URL another row already monitors, in any spelling', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/about' ] );

    $this->postJson( '/pagespeed/urls', [ 'url' => 'https://example.com/about/' ] )
        ->assertStatus( 409 )
        ->assertJsonPath( 'error', MonitoredUrlController::ERROR_ALREADY_MONITORED );

    expect( PageSpeedUrl::query()->count() )->toBe( 1 );
} );

it( 'refuses a URL PageSpeed cannot test', function ( mixed $url ): void {
    $this->postJson( '/pagespeed/urls', [ 'url' => $url ] )
        ->assertStatus( 422 )
        ->assertJsonPath( 'error', Controller::ERROR_INVALID_URL );
} )->with( [
    'blank'    => '',
    'scheme'   => 'mailto:someone@example.com',
    'not text' => [ [ 'https://example.com' ] ],
    'missing'  => null,
] );

it( 'refuses attributes it cannot store', function ( array $payload, string $field ): void {
    $this->postJson( '/pagespeed/urls', [ 'url' => 'https://example.com/about' ] + $payload )
        ->assertStatus( 422 )
        ->assertJsonPath( 'error', MonitoredUrlController::ERROR_INVALID_ATTRIBUTE )
        ->assertJsonPath( 'field', $field );

    expect( PageSpeedUrl::query()->count() )->toBe( 0 );
} )->with( [
    'long label'              => [ [ 'label' => str_repeat( 'a', 256 ) ], 'label' ],
    'label of the wrong type' => [ [ 'label' => [ 'a' ] ], 'label' ],
    'unknown strategy'        => [ [ 'strategies' => [ 'watch' ] ], 'strategies' ],
    'no strategy at all'      => [ [ 'strategies' => [] ], 'strategies' ],
    'unknown frequency'       => [ [ 'testFrequency' => 'fortnightly' ], 'testFrequency' ],
] );

/*
|--------------------------------------------------------------------------
| Removing
|--------------------------------------------------------------------------
*/

it( 'stops monitoring a URL', function (): void {
    $url = PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/about' ] );

    $this->deleteJson( '/pagespeed/urls/' . $url->getKey() )
        ->assertNoContent();

    expect( PageSpeedUrl::query()->count() )->toBe( 0 );
} );

it( 'answers 404 for an id no monitored URL carries', function (): void {
    $this->deleteJson( '/pagespeed/urls/9999' )
        ->assertNotFound()
        ->assertJsonPath( 'error', MonitoredUrlController::ERROR_NOT_FOUND );
} );
