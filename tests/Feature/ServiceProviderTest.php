<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Facades\PageSpeedInsights as PageSpeedInsightsFacade;
use ArtisanPackUI\PageSpeedInsights\PageSpeedInsights;
use ArtisanPackUI\PageSpeedInsights\PageSpeedInsightsServiceProvider;
use Illuminate\Support\ServiceProvider;

it( 'boots in a Testbench application with no configuration', function (): void {
    expect( app()->getProviders( PageSpeedInsightsServiceProvider::class ) )->not->toBeEmpty();
} );

it( 'registers the pagespeed-insights binding', function (): void {
    expect( app( 'pagespeed-insights' ) )->toBeInstanceOf( PageSpeedInsights::class );
} );

it( 'registers the binding as a singleton', function (): void {
    expect( app( 'pagespeed-insights' ) )->toBe( app( 'pagespeed-insights' ) );
} );

it( 'resolves the same instance through the facade', function (): void {
    expect( PageSpeedInsightsFacade::getFacadeRoot() )->toBe( app( 'pagespeed-insights' ) );
} );

it( 'resolves the same instance through the helper function', function (): void {
    expect( pageSpeedInsights() )->toBe( app( 'pagespeed-insights' ) );
} );

it( 'reports the package version through the facade', function (): void {
    expect( PageSpeedInsightsFacade::version() )->toBe( PageSpeedInsights::VERSION );
} );

it( 'merges the package configuration', function (): void {
    expect( config( 'pagespeed-insights.driver' ) )->toBe( 'config' );
    expect( config()->has( 'pagespeed-insights.api_key' ) )->toBeTrue();
} );

it( 'publishes the config and migrations under their own tags', function ( string $tag ): void {
    expect( ServiceProvider::pathsToPublish( PageSpeedInsightsServiceProvider::class, $tag ) )
        ->not->toBeEmpty();
} )->with( [
    'config'     => [ 'pagespeed-insights-config' ],
    'migrations' => [ 'pagespeed-insights-migrations' ],
] );
