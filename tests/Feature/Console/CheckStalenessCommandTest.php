<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Notifications\StaleUrlNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'pagespeed-insights.alerts.enabled', true );
    config()->set( 'pagespeed-insights.alerts.staleness.enabled', true );
    config()->set( 'pagespeed-insights.alerts.mail_to', [ 'ops@example.com' ] );
    config()->set( 'pagespeed-insights.scheduling.enabled', true );
    config()->set( 'pagespeed-insights.api_key', 'test-key' );
} );

if ( ! function_exists( 'psiStaleMonitoredUrl' ) ) {
    /**
     * Store a monitored URL whose last completed run is well past its window.
     *
     * @param  string  $url  The address to monitor.
     *
     * @return PageSpeedUrl The stored row.
     */
    function psiStaleMonitoredUrl( string $url = 'https://example.com/pricing' ): PageSpeedUrl
    {
        $row = PageSpeedUrl::factory()->create( [ 'url' => $url, 'test_frequency' => 'daily' ] );

        $row->forceFill( [ 'created_at' => CarbonImmutable::now()->subDays( 90 ) ] )->save();

        $result = PageSpeedResult::factory()->create( [
            'pagespeed_url_id' => $row->getKey(),
            'url'              => $url,
            'strategy'         => 'mobile',
        ] );

        $at = CarbonImmutable::now()->subDays( 5 );

        $result->forceFill( [ 'created_at' => $at, 'fetched_at' => $at ] )->save();

        return $row->refresh();
    }
}

it( 'reports the stale URLs and alerts about them', function (): void {
    Notification::fake();

    psiStaleMonitoredUrl();

    $this->artisan( 'pagespeed:check-staleness' )
        ->expectsOutputToContain( 'https://example.com/pricing' )
        ->expectsOutputToContain( '1 monitored URL(s) have stopped reporting.' )
        ->assertSuccessful();

    Notification::assertSentTimes( StaleUrlNotification::class, 1 );
} );

it( 'says so when nothing is stale', function (): void {
    Notification::fake();

    PageSpeedUrl::factory()->create( [ 'test_frequency' => 'daily' ] );

    $this->artisan( 'pagespeed:check-staleness' )
        ->expectsOutputToContain( 'No monitored URLs need a staleness alert.' )
        ->assertSuccessful();

    Notification::assertNothingSent();
} );

it( 'checks without alerting or consuming the repeat window on a dry run', function (): void {
    Notification::fake();

    psiStaleMonitoredUrl();

    $this->artisan( 'pagespeed:check-staleness', [ '--dry-run' => true ] )
        ->expectsOutputToContain( 'https://example.com/pricing' )
        ->assertSuccessful();

    Notification::assertNothingSent();

    // The dry run must not have marked the URL as already alerted about.
    $this->artisan( 'pagespeed:check-staleness' )->assertSuccessful();

    Notification::assertSentTimes( StaleUrlNotification::class, 1 );
} );

it( 'reports a dry run even when staleness alerting is switched off', function (): void {
    Notification::fake();

    config()->set( 'pagespeed-insights.alerts.staleness.enabled', false );

    psiStaleMonitoredUrl();

    $this->artisan( 'pagespeed:check-staleness', [ '--dry-run' => true ] )
        ->expectsOutputToContain( 'https://example.com/pricing' )
        ->assertSuccessful();

    $this->artisan( 'pagespeed:check-staleness' )
        ->expectsOutputToContain( 'Staleness alerting is turned off' )
        ->assertSuccessful();

    Notification::assertNothingSent();
} );

describe( 'the scheduled task', function (): void {
    it( 'runs hourly by default', function (): void {
        expect( psiScheduledStalenessEvents() )->toHaveCount( 1 )
            ->and( psiScheduledStalenessEvents()[ 0 ]->expression )->toBe( '0 * * * *' );
    } );

    it( 'is not registered when scheduling is disabled', function (): void {
        config( [ 'pagespeed-insights.scheduling.enabled' => false ] );

        expect( psiScheduledStalenessEvents() )->toHaveCount( 0 );
    } );
} );

/**
 * The scheduled entries for this package's staleness check.
 *
 * Resolving the scheduler is what registers them, so this must not be called
 * before a test has set the config it means to exercise.
 *
 * @return array<int, Event> The matching scheduled events.
 */
function psiScheduledStalenessEvents(): array
{
    return array_values( array_filter(
        app( Schedule::class )->events(),
        static fn ( Event $event ): bool => str_contains( (string) $event->command, 'pagespeed:check-staleness' ),
    ) );
}
