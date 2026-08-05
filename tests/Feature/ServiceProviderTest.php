<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Facades\PageSpeedInsights as PageSpeedInsightsFacade;
use ArtisanPackUI\PageSpeedInsights\Livewire\CoreWebVitalsCard;
use ArtisanPackUI\PageSpeedInsights\Livewire\OpportunitiesTable;
use ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard;
use ArtisanPackUI\PageSpeedInsights\Livewire\TrendChart;
use ArtisanPackUI\PageSpeedInsights\Livewire\UrlManager;
use ArtisanPackUI\PageSpeedInsights\PageSpeedInsights;
use ArtisanPackUI\PageSpeedInsights\PageSpeedInsightsServiceProvider;
use ArtisanPackUI\PageSpeedInsights\Support\LivewireInstalled;
use Illuminate\Support\ServiceProvider;
use Livewire\Mechanisms\ComponentRegistry as LivewireComponentRegistry;

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

it( 'publishes the Blade views under their own tag', function (): void {
    expect( ServiceProvider::pathsToPublish( PageSpeedInsightsServiceProvider::class, 'pagespeed-insights-views' ) )
        ->not->toBeEmpty();
} );

it( 'publishes the React and Vue sources under their own tag', function (): void {
    $paths = ServiceProvider::pathsToPublish( PageSpeedInsightsServiceProvider::class, 'pagespeed-insights-js' );

    expect( $paths )->not->toBeEmpty();
    // Published as sources rather than as a build: the components take their
    // styling from the host application's Tailwind and daisyUI theme, so they
    // have to reach its pipeline before it compiles.
    expect( array_key_first( $paths ) )->toEndWith( 'resources/js' );
} );

it( 'registers the package view namespace', function (): void {
    expect( view()->exists( 'pagespeed-insights::livewire.score-card' ) )->toBeTrue();
    expect( view()->exists( 'pagespeed-insights::livewire.core-web-vitals-card' ) )->toBeTrue();
    expect( view()->exists( 'pagespeed-insights::livewire.opportunities-table' ) )->toBeTrue();
    expect( view()->exists( 'pagespeed-insights::livewire.trend-chart' ) )->toBeTrue();
    expect( view()->exists( 'pagespeed-insights::livewire.url-manager' ) )->toBeTrue();
} );

it( 'registers the Livewire components under their documented aliases', function ( string $alias, string $class ): void {
    expect( app( LivewireComponentRegistry::class )->getClass( $alias ) )->toBe( $class );
} )->with( [
    'score card'      => [ 'pagespeed-score-card', ScoreCard::class ],
    'core web vitals' => [ 'pagespeed-core-web-vitals', CoreWebVitalsCard::class ],
    'opportunities'   => [ 'pagespeed-opportunities', OpportunitiesTable::class ],
    'trend chart'     => [ 'pagespeed-trend-chart', TrendChart::class ],
    'url manager'     => [ 'pagespeed-url-manager', UrlManager::class ],
] );

it( 'skips Livewire registration entirely when Livewire is not installed', function (): void {
    LivewireInstalled::setForTesting( false );

    $provider = new PageSpeedInsightsServiceProvider( app() );

    // Booting the provider a second time with Livewire reported absent must
    // not touch Livewire at all. A throw here would mean the guard is not
    // doing its job and an install without Livewire would fail to boot.
    expect( fn () => $provider->boot() )->not->toThrow( Throwable::class );
} );
