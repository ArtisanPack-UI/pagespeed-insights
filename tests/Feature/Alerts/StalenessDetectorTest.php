<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Alerts\StalenessDetector;
use ArtisanPackUI\PageSpeedInsights\Alerts\StaleUrl;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Notifications\StaleUrlNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'pagespeed-insights.alerts.enabled', true );
    config()->set( 'pagespeed-insights.alerts.staleness.enabled', true );
    config()->set( 'pagespeed-insights.alerts.staleness.missed_cycles', 2 );
    config()->set( 'pagespeed-insights.alerts.staleness.repeat_after', 86400 );
    config()->set( 'pagespeed-insights.alerts.mail_to', [ 'ops@example.com' ] );
    config()->set( 'pagespeed-insights.scheduling.enabled', true );
    config()->set( 'pagespeed-insights.driver', 'config' );
    config()->set( 'pagespeed-insights.api_key', 'test-key' );
} );

if ( ! function_exists( 'psiMonitoredUrl' ) ) {
    /**
     * Store a monitored URL that was added long enough ago to be measurable.
     *
     * The row's own `created_at` is the baseline for a URL that has never
     * completed a run, so a freshly created one is never stale — which is the
     * right default and the wrong starting point for most of these cases.
     *
     * Created never-tested so the row means what these cases say it means.
     * `last_tested_at` is written only by a completed run, and the detector
     * reads it as one, so leaving the factory's default two-hours-ago stamp on
     * a row described as having never run would be setting up a contradiction
     * and asserting against it.
     *
     * @param  array<string, mixed>  $attributes  Attribute overrides.
     * @param  int  $addedDaysAgo  How long ago the row was created.
     *
     * @return PageSpeedUrl The stored row.
     */
    function psiMonitoredUrl( array $attributes = [], int $addedDaysAgo = 90 ): PageSpeedUrl
    {
        $url = PageSpeedUrl::factory()->neverTested()->create( array_merge(
            [ 'url' => 'https://example.com/pricing', 'label' => null ],
            $attributes,
        ) );

        $url->forceFill( [ 'created_at' => CarbonImmutable::now()->subDays( $addedDaysAgo ) ] )->save();

        return $url->refresh();
    }
}

if ( ! function_exists( 'psiRunFor' ) ) {
    /**
     * Store one run for a monitored URL, at a given age.
     *
     * @param  PageSpeedUrl  $url  The monitored row.
     * @param  int  $minutesAgo  How long ago the run was written.
     * @param  bool  $failed  Whether the run failed.
     * @param  string|null  $error  The failure's message.
     *
     * @return PageSpeedResult The stored run.
     */
    function psiRunFor(
        PageSpeedUrl $url,
        int $minutesAgo,
        bool $failed = false,
        ?string $error = null,
    ): PageSpeedResult {
        $factory = $failed ? PageSpeedResult::factory()->failed( $error ) : PageSpeedResult::factory();

        $result = $factory->create( [
            'pagespeed_url_id' => $url->getKey(),
            'url'              => $url->url,
            'strategy'         => 'mobile',
        ] );

        $at = CarbonImmutable::now()->subMinutes( $minutesAgo );

        $result->forceFill( [ 'created_at' => $at, 'fetched_at' => $at ] )->save();

        return $result->refresh();
    }
}

it( 'reports a URL whose cadence has elapsed twice over', function (): void {
    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 3 );

    $stale = app( StalenessDetector::class )->detect();

    expect( $stale )->toHaveCount( 1 )
        ->and( $stale[ 0 ]->url )->toBe( 'https://example.com/pricing' )
        ->and( $stale[ 0 ]->frequency )->toBe( 'daily' )
        ->and( $stale[ 0 ]->urlId )->toBe( $url->getKey() );
} );

it( 'leaves a URL alone while it is inside its window', function (): void {
    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 30 );

    expect( app( StalenessDetector::class )->detect() )->toBe( [] );
} );

it( 'measures the window against the per-URL frequency', function (): void {
    $hourly = psiMonitoredUrl( [ 'url' => 'https://example.com/hourly', 'test_frequency' => 'hourly' ] );
    $weekly = psiMonitoredUrl( [ 'url' => 'https://example.com/weekly', 'test_frequency' => 'weekly' ] );

    // Five hours is long past two hourly cycles and nowhere near two weekly
    // ones, from the one elapsed duration.
    psiRunFor( $hourly, 60 * 5 );
    psiRunFor( $weekly, 60 * 5 );

    $stale = app( StalenessDetector::class )->detect();

    expect( $stale )->toHaveCount( 1 )
        ->and( $stale[ 0 ]->url )->toBe( 'https://example.com/hourly' );
} );

it( 'falls back to last_tested_at when the result rows have been pruned', function (): void {
    // A retention window shorter than the cadence times the tolerance sweeps
    // the completed rows before the staleness window elapses. Without this
    // fallback a perfectly healthy URL reports as stale, and — the failure
    // rows having been swept too — is diagnosed "the runs are not reaching a
    // worker", which is a confident and wrong sentence about a healthy system.
    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );

    $url->forceFill( [ 'last_tested_at' => CarbonImmutable::now()->subHour() ] )->save();

    expect( PageSpeedResult::query()->count() )->toBe( 0 )
        ->and( app( StalenessDetector::class )->detect() )->toBe( [] );
} );

it( 'still reports a URL whose last_tested_at is itself long past', function (): void {
    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );

    $url->forceFill( [ 'last_tested_at' => CarbonImmutable::now()->subDays( 5 ) ] )->save();

    expect( app( StalenessDetector::class )->detect() )->toHaveCount( 1 );
} );

it( 'prefers a stored run to last_tested_at when both are there', function (): void {
    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );

    psiRunFor( $url, 60 * 24 * 3 );

    // The stamp is newer, but the results table is the more precise record and
    // stays authoritative wherever it still has the answer.
    $url->forceFill( [ 'last_tested_at' => CarbonImmutable::now()->subHour() ] )->save();

    expect( app( StalenessDetector::class )->detect() )->toHaveCount( 1 );
} );

it( 'honours the configured missed cycle tolerance', function (): void {
    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 3 );

    config()->set( 'pagespeed-insights.alerts.staleness.missed_cycles', 5 );

    expect( app( StalenessDetector::class )->detect() )->toBe( [] );
} );

it( 'falls back to the default tolerance when the configured one is unusable', function (): void {
    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 3 );

    config()->set( 'pagespeed-insights.alerts.staleness.missed_cycles', 0 );

    $stale = app( StalenessDetector::class )->detect();

    expect( $stale )->toHaveCount( 1 )
        ->and( $stale[ 0 ]->missedCycles )->toBe( StalenessDetector::DEFAULT_MISSED_CYCLES );
} );

it( 'ignores paused URLs', function (): void {
    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily', 'is_active' => false ] );
    psiRunFor( $url, 60 * 24 * 30 );

    expect( app( StalenessDetector::class )->detect() )->toBe( [] );
} );

it( 'does not call a URL that has only just been added stale', function (): void {
    psiMonitoredUrl( [ 'test_frequency' => 'daily' ], addedDaysAgo: 0 );

    expect( app( StalenessDetector::class )->detect() )->toBe( [] );
} );

it( 'reports a URL added long ago that has never completed a run', function (): void {
    psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );

    $stale = app( StalenessDetector::class )->detect();

    expect( $stale )->toHaveCount( 1 )
        ->and( $stale[ 0 ]->lastResultAt )->toBeNull();
} );

it( 'ignores failed runs when deciding whether a URL is reporting', function (): void {
    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 5 );
    psiRunFor( $url, 10, failed: true, error: 'The PageSpeed request timed out.' );

    expect( app( StalenessDetector::class )->detect() )->toHaveCount( 1 );
} );

it( 'names the failed runs and the last error as the likely cause', function (): void {
    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 5 );
    psiRunFor( $url, 60 * 24, failed: true, error: 'The monitored URL returned HTTP 404.' );
    psiRunFor( $url, 60 * 12, failed: true, error: 'The monitored URL returned HTTP 404.' );

    $stale = app( StalenessDetector::class )->detect();

    expect( $stale[ 0 ]->cause )->toBe( StaleUrl::CAUSE_FAILING )
        ->and( $stale[ 0 ]->failures )->toBe( 2 )
        ->and( $stale[ 0 ]->causeDetail )->toBe( 'The monitored URL returned HTTP 404.' )
        ->and( $stale[ 0 ]->describe() )->toContain( 'The monitored URL returned HTTP 404.' );
} );

it( 'names the queue or scheduler when nothing was recorded at all', function (): void {
    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 5 );

    $stale = app( StalenessDetector::class )->detect();

    expect( $stale[ 0 ]->cause )->toBe( StaleUrl::CAUSE_NOT_RUNNING )
        ->and( $stale[ 0 ]->failures )->toBe( 0 )
        ->and( $stale[ 0 ]->describe() )->toContain( 'not reaching a worker' );
} );

it( 'names a missing API key ahead of the failures it caused', function (): void {
    config()->set( 'pagespeed-insights.api_key', null );

    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 5 );
    psiRunFor( $url, 60, failed: true, error: 'No PageSpeed Insights API key is configured.' );

    $stale = app( StalenessDetector::class )->detect();

    expect( $stale[ 0 ]->cause )->toBe( StaleUrl::CAUSE_NO_API_KEY )
        ->and( $stale[ 0 ]->describe() )->toContain( 'PAGESPEED_API_KEY' );
} );

it( 'names switched-off scheduling as the cause', function (): void {
    config()->set( 'pagespeed-insights.scheduling.enabled', false );

    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 5 );

    $stale = app( StalenessDetector::class )->detect();

    expect( $stale[ 0 ]->cause )->toBe( StaleUrl::CAUSE_SCHEDULING_DISABLED )
        ->and( $stale[ 0 ]->describe() )->toContain( 'scheduling.enabled' );
} );

it( 'sends one notification covering every stale URL', function (): void {
    Notification::fake();

    foreach ( [ 'one', 'two', 'three' ] as $slug ) {
        $url = psiMonitoredUrl( [ 'url' => 'https://example.com/' . $slug, 'test_frequency' => 'daily' ] );
        psiRunFor( $url, 60 * 24 * 5 );
    }

    $alerted = app( StalenessDetector::class )->handle();

    expect( $alerted )->toHaveCount( 3 );

    Notification::assertSentTimes( StaleUrlNotification::class, 1 );
    Notification::assertSentOnDemand(
        StaleUrlNotification::class,
        function ( StaleUrlNotification $notification ): bool {
            return 3 === count( $notification->stale );
        },
    );
} );

it( 'does not alert about the same URL twice inside the repeat window', function (): void {
    Notification::fake();

    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 5 );

    expect( app( StalenessDetector::class )->handle() )->toHaveCount( 1 )
        ->and( app( StalenessDetector::class )->handle() )->toBe( [] );

    Notification::assertSentTimes( StaleUrlNotification::class, 1 );
} );

it( 'alerts again on the next pass when the notification could not be delivered', function (): void {
    psiFailingNotifications();

    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 5 );

    expect( app( StalenessDetector::class )->handle() )->toHaveCount( 1 );

    // The mailer comes back before the repeat window is anywhere near over.
    Notification::fake();
    psiWorkingNotifications();

    expect( app( StalenessDetector::class )->handle() )->toHaveCount( 1 );

    Notification::assertSentTimes( StaleUrlNotification::class, 1 );
} );

it( 'stays quiet on the next pass when nobody is configured to receive the alert', function (): void {
    Notification::fake();

    config()->set( 'pagespeed-insights.alerts.mail_to', [] );
    config()->set( 'pagespeed-insights.alerts.notifiable', null );

    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 5 );

    expect( app( StalenessDetector::class )->handle() )->toHaveCount( 1 )
        ->and( app( StalenessDetector::class )->handle() )->toBe( [] );

    Notification::assertNothingSent();
} );

it( 'alerts on every pass when the repeat window is turned off', function (): void {
    Notification::fake();

    config()->set( 'pagespeed-insights.alerts.staleness.repeat_after', 0 );

    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 5 );

    app( StalenessDetector::class )->handle();
    app( StalenessDetector::class )->handle();

    Notification::assertSentTimes( StaleUrlNotification::class, 2 );
} );

it( 'sends nothing when staleness alerting is switched off', function (): void {
    Notification::fake();

    config()->set( 'pagespeed-insights.alerts.staleness.enabled', false );

    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 5 );

    expect( app( StalenessDetector::class )->handle() )->toBe( [] );

    Notification::assertNothingSent();
} );

it( 'sends nothing when alerting is switched off entirely', function (): void {
    Notification::fake();

    config()->set( 'pagespeed-insights.alerts.enabled', false );

    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 5 );

    expect( app( StalenessDetector::class )->enabled() )->toBeFalse()
        ->and( app( StalenessDetector::class )->handle() )->toBe( [] );

    Notification::assertNothingSent();
} );

it( 'fires the went-stale action with the URL and the diagnosis', function (): void {
    Notification::fake();

    $seen = [];

    addAction(
        StalenessDetector::ACTION_URL_WENT_STALE,
        function ( ?PageSpeedUrl $url, array $diagnosis ) use ( &$seen ): void {
            $seen[] = [ 'url' => $url, 'diagnosis' => $diagnosis ];
        },
    );

    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily', 'label' => 'Pricing' ] );
    psiRunFor( $url, 60 * 24 * 5 );

    app( StalenessDetector::class )->handle();

    expect( $seen )->toHaveCount( 1 )
        ->and( $seen[ 0 ][ 'url' ]?->getKey() )->toBe( $url->getKey() )
        ->and( $seen[ 0 ][ 'diagnosis' ][ 'url' ] )->toBe( 'https://example.com/pricing' )
        ->and( $seen[ 0 ][ 'diagnosis' ][ 'label' ] )->toBe( 'Pricing' )
        ->and( $seen[ 0 ][ 'diagnosis' ][ 'frequency' ] )->toBe( 'daily' )
        ->and( $seen[ 0 ][ 'diagnosis' ][ 'cause' ] )->toBe( StaleUrl::CAUSE_NOT_RUNNING )
        ->and( $seen[ 0 ][ 'diagnosis' ][ 'missed_cycles' ] )->toBe( 2 );

    removeAllActions( StalenessDetector::ACTION_URL_WENT_STALE );
} );

it( 'fires the action again for a URL whose alert could not be delivered', function (): void {
    psiFailingNotifications();

    $fired = 0;

    addAction( StalenessDetector::ACTION_URL_WENT_STALE, function () use ( &$fired ): void {
        ++$fired;
    } );

    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 5 );

    app( StalenessDetector::class )->handle();
    app( StalenessDetector::class )->handle();

    expect( $fired )->toBe( 2 );

    removeAllActions( StalenessDetector::ACTION_URL_WENT_STALE );
} );

it( 'does not fire the action for a URL held back by the repeat window', function (): void {
    Notification::fake();

    $fired = 0;

    addAction( StalenessDetector::ACTION_URL_WENT_STALE, function () use ( &$fired ): void {
        ++$fired;
    } );

    $url = psiMonitoredUrl( [ 'test_frequency' => 'daily' ] );
    psiRunFor( $url, 60 * 24 * 5 );

    app( StalenessDetector::class )->handle();
    app( StalenessDetector::class )->handle();

    expect( $fired )->toBe( 1 );

    removeAllActions( StalenessDetector::ACTION_URL_WENT_STALE );
} );
