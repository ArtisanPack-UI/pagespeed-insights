<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\HttpUser;

uses( RefreshDatabase::class );

it( 'registers every endpoint under the configured prefix', function (): void {
    $paths = collect( Route::getRoutes() )
        ->map( static fn ( $route ): string => $route->methods()[ 0 ] . ' ' . $route->uri() )
        ->values()
        ->all();

    expect( $paths )
        ->toContain( 'GET pagespeed/scores' )
        ->toContain( 'GET pagespeed/core-web-vitals' )
        ->toContain( 'GET pagespeed/opportunities' )
        ->toContain( 'GET pagespeed/trends' )
        ->toContain( 'GET pagespeed/urls' )
        ->toContain( 'POST pagespeed/urls' )
        ->toContain( 'DELETE pagespeed/urls/{id}' )
        ->toContain( 'POST pagespeed/test' )
        ->toContain( 'GET pagespeed/results/{id}' );
} );

it( 'names every endpoint', function ( string $name ): void {
    expect( Route::has( $name ) )->toBeTrue();
} )->with( [
    'pagespeed-insights.scores',
    'pagespeed-insights.core-web-vitals',
    'pagespeed-insights.opportunities',
    'pagespeed-insights.trends',
    'pagespeed-insights.urls.index',
    'pagespeed-insights.urls.store',
    'pagespeed-insights.urls.destroy',
    'pagespeed-insights.test',
    'pagespeed-insights.results.show',
] );

it( 'refuses every endpoint to an unauthenticated request', function ( string $method, string $path ): void {
    $this->json( $method, $path )->assertUnauthorized();
} )->with( [
    [ 'GET', '/pagespeed/scores?url=https://example.com/page' ],
    [ 'GET', '/pagespeed/core-web-vitals?url=https://example.com/page' ],
    [ 'GET', '/pagespeed/opportunities?url=https://example.com/page' ],
    [ 'GET', '/pagespeed/trends?url=https://example.com/page' ],
    [ 'GET', '/pagespeed/urls' ],
    [ 'POST', '/pagespeed/urls' ],
    [ 'DELETE', '/pagespeed/urls/1' ],
    [ 'POST', '/pagespeed/test' ],
    [ 'GET', '/pagespeed/results/1' ],
] );

it( 'serves an authenticated request', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/page' ] );

    $this->actingAs( new HttpUser() )
        ->getJson( '/pagespeed/scores?url=https://example.com/page' )
        ->assertOk();
} );

it( 'adds no ability check unless one is configured', function (): void {
    // The regression guard that matters most for `routes.ability`: it is
    // additive, so an installation that never sets it must be untouched.
    $middleware = collect( Route::getRoutes() )
        ->filter( static fn ( $route ): bool => str_starts_with( $route->uri(), 'pagespeed/' ) )
        ->flatMap( static fn ( $route ): array => $route->gatherMiddleware() )
        ->unique()
        ->values()
        ->all();

    expect( $middleware )->toContain( 'auth' )
        ->and( $middleware )->not->toContain( 'can:view_pagespeed_insights' );

    foreach ( $middleware as $entry ) {
        expect( str_starts_with( (string) $entry, 'can:' ) )->toBeFalse();
    }
} );
