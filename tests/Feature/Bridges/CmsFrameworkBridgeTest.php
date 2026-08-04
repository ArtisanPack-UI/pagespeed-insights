<?php

declare( strict_types=1 );

use ArtisanPackUI\CMSFramework\Modules\AdminWidgets\Contracts\AdminWidgetInterface;
use ArtisanPackUI\CMSFramework\Modules\AdminWidgets\Services\AdminWidgetManager;
use ArtisanPackUI\PageSpeedInsights\Bridges\CmsFramework\AdminWidgets\CoreWebVitalsWidget;
use ArtisanPackUI\PageSpeedInsights\Bridges\CmsFramework\AdminWidgets\ScoreCardWidget;
use ArtisanPackUI\PageSpeedInsights\Bridges\CmsFramework\AdminWidgets\TrendChartWidget;
use ArtisanPackUI\PageSpeedInsights\Livewire\CoreWebVitalsCard;
use ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard;
use ArtisanPackUI\PageSpeedInsights\Livewire\TrendChart;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\PageSpeedInsightsServiceProvider;
use ArtisanPackUI\PageSpeedInsights\Support\CmsFrameworkInstalled;
use ArtisanPackUI\PageSpeedInsights\Support\LivewireInstalled;
use ArtisanPackUI\PageSpeedInsights\Support\TrendSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'pagespeed-insights.driver', 'config' );
    config()->set( 'pagespeed-insights.api_key', 'test-key' );
    config()->set( 'app.url', 'https://example.com' );
} );

it( 'each widget wrapper extends the Livewire component it exposes', function (): void {
    expect( is_subclass_of( ScoreCardWidget::class, ScoreCard::class ) )->toBeTrue();
    expect( is_subclass_of( CoreWebVitalsWidget::class, CoreWebVitalsCard::class ) )->toBeTrue();
    expect( is_subclass_of( TrendChartWidget::class, TrendChart::class ) )->toBeTrue();
} );

it( 'each widget wrapper implements the CMS AdminWidgetInterface contract', function (): void {
    expect( is_subclass_of( ScoreCardWidget::class, AdminWidgetInterface::class ) )->toBeTrue();
    expect( is_subclass_of( CoreWebVitalsWidget::class, AdminWidgetInterface::class ) )->toBeTrue();
    expect( is_subclass_of( TrendChartWidget::class, AdminWidgetInterface::class ) )->toBeTrue();
} );

it( 'exposes a widget type map covering all three widget types wired to the right wrappers', function (): void {
    expect( PageSpeedInsightsServiceProvider::cmsFrameworkWidgetTypeMap() )->toBe( [
        'pagespeed-insights.score-card'      => ScoreCardWidget::class,
        'pagespeed-insights.core-web-vitals' => CoreWebVitalsWidget::class,
        'pagespeed-insights.trend-chart'     => TrendChartWidget::class,
    ] );
} );

it( 'ScoreCardWidget metadata pins the title, description, capability, and defaults', function (): void {
    $info = ScoreCardWidget::getWidgetInfo();

    expect( $info )->toHaveKeys( [ 'title', 'description', 'capability', 'default_options' ] );
    expect( $info[ 'title' ] )->toBe( 'PageSpeed scores' );
    expect( $info[ 'description' ] )
        ->toBe( 'The four Lighthouse category scores from the most recent stored run.' );
    expect( $info[ 'capability' ] )->toBe( 'view_pagespeed_insights' );
    expect( $info[ 'default_options' ] )->toBe( [
        'url'      => '',
        'strategy' => 'mobile',
    ] );
} );

it( 'CoreWebVitalsWidget metadata pins the title, description, capability, and defaults', function (): void {
    $info = CoreWebVitalsWidget::getWidgetInfo();

    expect( $info[ 'title' ] )->toBe( 'Core Web Vitals' );
    expect( $info[ 'description' ] )
        ->toBe( 'LCP, INP, and CLS from CrUX field data, banded against Google\'s thresholds.' );
    expect( $info[ 'capability' ] )->toBe( 'view_pagespeed_insights' );
    expect( $info[ 'default_options' ] )->toBe( [
        'url'      => '',
        'strategy' => 'mobile',
    ] );
} );

it( 'TrendChartWidget metadata pins the title, description, capability, and defaults', function (): void {
    $info = TrendChartWidget::getWidgetInfo();

    expect( $info[ 'title' ] )->toBe( 'PageSpeed trend' );
    expect( $info[ 'description' ] )
        ->toBe( 'One measurement plotted over time, mobile against desktop.' );
    expect( $info[ 'capability' ] )->toBe( 'view_pagespeed_insights' );
    expect( $info[ 'default_options' ] )->toBe( [
        'url'    => '',
        'metric' => TrendSeries::DEFAULT_METRIC,
        'range'  => TrendSeries::DEFAULT_RANGE,
    ] );
} );

it( 'leaves the default URL unresolved and non-null in the stored options', function (): void {
    // Two things at once. Resolving app.url into default_options would pin
    // the widget to whatever the site was called on the day somebody dropped
    // it on the dashboard. And a *null* default would never render at all:
    // Livewire assigns a matching option onto the public property before
    // mount() is reached, and `$url` is a non-nullable string.
    foreach ( [ ScoreCardWidget::class, CoreWebVitalsWidget::class, TrendChartWidget::class ] as $widget ) {
        expect( $widget::getWidgetInfo()[ 'default_options' ][ 'url' ] )->toBe( '' );
    }
} );

it( 'registers all three widgets on the container-bound manager during boot, and no more', function (): void {
    // The stubs are on the autoloader, so the Testbench boot ran with
    // CmsFrameworkInstalled::check() returning true and the provider hooked
    // its map into the manager the TestCase bound as a singleton.
    $registered = app( AdminWidgetManager::class )->getRegistered();

    expect( $registered )->toBe( [
        'pagespeed-insights.score-card'      => ScoreCardWidget::class,
        'pagespeed-insights.core-web-vitals' => CoreWebVitalsWidget::class,
        'pagespeed-insights.trend-chart'     => TrendChartWidget::class,
    ] );

    // Guards against a non-idempotent boot leaking duplicates.
    expect( $registered )->toHaveCount( 3 );
} );

it( 'the manager can describe every registered widget from its pinned metadata', function (): void {
    $available = app( AdminWidgetManager::class )->getAvailableWidgets();

    expect( array_keys( $available ) )->toEqual( [
        'pagespeed-insights.score-card',
        'pagespeed-insights.core-web-vitals',
        'pagespeed-insights.trend-chart',
    ] );

    expect( $available[ 'pagespeed-insights.score-card' ][ 'title' ] )->toBe( 'PageSpeed scores' );
    expect( $available[ 'pagespeed-insights.core-web-vitals' ][ 'title' ] )->toBe( 'Core Web Vitals' );
    expect( $available[ 'pagespeed-insights.trend-chart' ][ 'title' ] )->toBe( 'PageSpeed trend' );
} );

it( 'creating a widget through the manager seeds it with the wrapper class and its defaults', function (): void {
    $widget = app( AdminWidgetManager::class )->createWidget( 'pagespeed-insights.score-card' );

    expect( $widget )->not->toBeNull();
    expect( $widget[ 'component_class' ] )->toBe( ScoreCardWidget::class );
    expect( $widget[ 'capability' ] )->toBe( 'view_pagespeed_insights' );
    expect( $widget[ 'options' ] )->toBe( [
        'url'      => '',
        'strategy' => 'mobile',
    ] );
} );

it( 'registers a Livewire alias for each widget alongside the base component aliases', function (): void {
    $resolved = app( ComponentRegistry::class );

    expect( $resolved->getClass( 'pagespeed-score-card-widget' ) )->toBe( ScoreCardWidget::class );
    expect( $resolved->getClass( 'pagespeed-core-web-vitals-widget' ) )->toBe( CoreWebVitalsWidget::class );
    expect( $resolved->getClass( 'pagespeed-trend-chart-widget' ) )->toBe( TrendChartWidget::class );

    // The base aliases still point at the base components.
    expect( $resolved->getClass( 'pagespeed-score-card' ) )->toBe( ScoreCard::class );
} );

it( 'registers nothing when the CMS framework is absent, leaving the manager untouched', function (): void {
    CmsFrameworkInstalled::setForTesting( false );

    // A fresh empty manager in place of the booted singleton, so an empty
    // registry is proof the guard held rather than proof of nothing.
    $manager = new AdminWidgetManager();
    $this->app->instance( AdminWidgetManager::class, $manager );

    invokeCmsFrameworkWidgetRegistration( $this->app );

    expect( $manager->getRegistered() )->toBe( [] );
} );

it( 'registers nothing when Livewire is absent, since the wrappers are Livewire components', function (): void {
    LivewireInstalled::setForTesting( false );

    $manager = new AdminWidgetManager();
    $this->app->instance( AdminWidgetManager::class, $manager );

    invokeCmsFrameworkWidgetRegistration( $this->app );

    expect( $manager->getRegistered() )->toBe( [] );
} );

it( 'boots cleanly with the CMS framework absent, and still registers the base components', function (): void {
    CmsFrameworkInstalled::setForTesting( false );

    $provider = new PageSpeedInsightsServiceProvider( $this->app );

    $provider->boot();

    expect( app( ComponentRegistry::class )->getClass( 'pagespeed-score-card' ) )
        ->toBe( ScoreCard::class );
} );

it( 'ScoreCardWidget mounted with no URL describes the application home page', function (): void {
    PageSpeedResult::factory()->create( [
        'url'               => 'https://example.com/',
        'strategy'          => 'mobile',
        'performance_score' => 94,
    ] );

    Livewire::test( ScoreCardWidget::class )
        ->assertSet( 'url', 'https://example.com/' )
        ->assertSet( 'state', ScoreCard::STATE_LOADED )
        ->assertSee( '94' );
} );

it( 'CoreWebVitalsWidget and TrendChartWidget mounted with no URL describe the home page too', function (): void {
    Livewire::test( CoreWebVitalsWidget::class )
        ->assertSet( 'url', 'https://example.com/' );

    Livewire::test( TrendChartWidget::class )
        ->assertSet( 'url', 'https://example.com/' )
        ->assertSet( 'metric', TrendSeries::DEFAULT_METRIC )
        ->assertSet( 'range', TrendSeries::DEFAULT_RANGE );
} );

it( 'mounts every widget from the exact options the dashboard seeds it with', function (): void {
    // The path the "Add Widget" picker actually takes: createWidget() seeds
    // the options from getWidgetInfo(), and the dashboard hands those same
    // options straight back to mount(). The seeded URL is null, so a
    // non-nullable mount parameter would be a TypeError on a widget's very
    // first render — the one render nobody can avoid.
    $manager = app( AdminWidgetManager::class );

    foreach ( PageSpeedInsightsServiceProvider::cmsFrameworkWidgetTypeMap() as $type => $class ) {
        $seeded = $manager->createWidget( $type );

        Livewire::test( $class, $seeded[ 'options' ] )
            ->assertSet( 'url', 'https://example.com/' )
            ->assertOk();
    }
} );

it( 'keeps the URL locked, so a dashboard viewer cannot retarget a widget from the browser', function (): void {
    // Inherited from the base component, and worth pinning here: a widget
    // spends API quota and stores history, so a viewer who could rewrite
    // `url` in the request payload could have the application test — and
    // keep records about — an address of their choosing.
    Livewire::test( ScoreCardWidget::class )
        ->set( 'url', 'https://attacker.test/page' );
} )->throws( CannotUpdateLockedPropertyException::class );

it( 'a URL given to a widget wins over the home page', function (): void {
    Livewire::test( ScoreCardWidget::class, [ 'url' => 'https://other.test/page' ] )
        ->assertSet( 'url', 'https://other.test/page' );
} );

it( 'canonicalizes the home page the same way a stored URL is canonicalized', function (): void {
    // The widget has to read the row the monitored home page writes, so it
    // cannot go to the database with a near-miss spelling of it.
    config()->set( 'app.url', 'HTTP://Example.COM:80/home/' );

    Livewire::test( ScoreCardWidget::class )
        ->assertSet( 'url', 'http://example.com/home' );
} );

it( 'falls back to no URL rather than inventing one when app.url is not testable', function (): void {
    config()->set( 'app.url', 'mailto:ops@example.com' );

    Livewire::test( ScoreCardWidget::class )
        ->assertSet( 'url', '' )
        ->assertSet( 'state', ScoreCard::STATE_EMPTY );
} );

it( 'falls back to no URL when app.url is unset', function (): void {
    config()->set( 'app.url', null );

    Livewire::test( ScoreCardWidget::class )
        ->assertSet( 'url', '' )
        ->assertSet( 'state', ScoreCard::STATE_EMPTY );
} );

/**
 * Invoke only the guarded registration method, rather than the whole boot,
 * so the guard is proven to short-circuit without side-effecting routes,
 * publishes, or the other Livewire aliases. Reflection because the method is
 * deliberately protected — this is the one place the suite looks through it.
 */
function invokeCmsFrameworkWidgetRegistration( Illuminate\Contracts\Foundation\Application $app ): void
{
    $method = new ReflectionMethod( PageSpeedInsightsServiceProvider::class, 'registerCmsFrameworkWidgets' );
    $method->setAccessible( true );
    $method->invoke( new PageSpeedInsightsServiceProvider( $app ) );
}
