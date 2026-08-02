<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Facades\PageSpeedInsights as PageSpeedInsightsFacade;
use ArtisanPackUI\PageSpeedInsights\PageSpeedInsights;
use ArtisanPackUI\PageSpeedInsights\PageSpeedInsightsServiceProvider;

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
