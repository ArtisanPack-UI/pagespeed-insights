<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config( [ 'pagespeed-insights.api_key' => 'test-key' ] );

    Queue::fake();
} );

it( 'queues the URLs that are due', function (): void {
    PageSpeedUrl::factory()->neverTested()->create( [ 'url' => 'https://example.com/about' ] );

    $this->artisan( 'pagespeed:monitor' )
        ->expectsOutputToContain( 'Queued 2 test(s) across 1 URL(s).' )
        ->assertSuccessful();

    Queue::assertPushed( RunPageSpeedTest::class, 2 );
} );

it( 'says so when nothing is due', function (): void {
    PageSpeedUrl::factory()->notDue( 'weekly' )->create();

    $this->artisan( 'pagespeed:monitor' )
        ->expectsOutputToContain( 'No monitored URLs are due' )
        ->assertSuccessful();

    Queue::assertNothingPushed();
} );

it( 'fails with an actionable message when no API key is configured', function (): void {
    config( [ 'pagespeed-insights.api_key' => null ] );

    PageSpeedUrl::factory()->count( 3 )->neverTested()->create();

    $this->artisan( 'pagespeed:monitor' )
        ->expectsOutputToContain( 'PAGESPEED_API_KEY' )
        ->assertFailed();

    Queue::assertNothingPushed();
} );

describe( 'the --url option', function (): void {
    it( 'queues only that URL, even when it is not due', function (): void {
        PageSpeedUrl::factory()->notDue( 'monthly' )->strategy()->create( [ 'url' => 'https://example.com/about' ] );
        PageSpeedUrl::factory()->neverTested()->create( [ 'url' => 'https://example.com/pricing' ] );

        $this->artisan( 'pagespeed:monitor', [ '--url' => 'https://example.com/about' ] )
            ->expectsOutputToContain( 'Queued 1 test(s) across 1 URL(s).' )
            ->assertSuccessful();

        Queue::assertPushed(
            RunPageSpeedTest::class,
            static fn ( RunPageSpeedTest $job ): bool => 'https://example.com/about' === $job->url,
        );

        Queue::assertPushed( RunPageSpeedTest::class, 1 );
    } );

    it( 'matches a URL in any spelling', function (): void {
        PageSpeedUrl::factory()->strategy()->create( [ 'url' => 'https://example.com/about' ] );

        $this->artisan( 'pagespeed:monitor', [ '--url' => 'HTTPS://Example.com/about/#team' ] )
            ->assertSuccessful();

        Queue::assertPushed( RunPageSpeedTest::class, 1 );
    } );

    it( 'warns before testing a paused URL', function (): void {
        PageSpeedUrl::factory()->inactive()->strategy()->create( [ 'url' => 'https://example.com/about' ] );

        $this->artisan( 'pagespeed:monitor', [ '--url' => 'https://example.com/about' ] )
            ->expectsOutputToContain( 'paused' )
            ->assertSuccessful();

        Queue::assertPushed( RunPageSpeedTest::class, 1 );
    } );

    it( 'fails when the URL is not monitored', function (): void {
        $this->artisan( 'pagespeed:monitor', [ '--url' => 'https://example.com/nowhere' ] )
            ->expectsOutputToContain( 'is not a monitored URL' )
            ->assertFailed();

        Queue::assertNothingPushed();
    } );
} );

describe( 'the scheduled task', function (): void {
    it( 'runs hourly by default', function (): void {
        expect( psiScheduledMonitorEvents() )->toHaveCount( 1 )
            ->and( psiScheduledMonitorEvents()[ 0 ]->expression )->toBe( '0 * * * *' );
    } );

    it( 'is not registered when scheduling is disabled', function (): void {
        config( [ 'pagespeed-insights.scheduling.enabled' => false ] );

        expect( psiScheduledMonitorEvents() )->toHaveCount( 0 );
    } );
} );

/**
 * The scheduled entries for this package's monitoring command.
 *
 * Resolving the scheduler is what registers them, so this must not be called
 * before a test has set the config it means to exercise.
 *
 * @return array<int, Event> The matching scheduled events.
 */
function psiScheduledMonitorEvents(): array
{
    return array_values( array_filter(
        app( Schedule::class )->events(),
        static fn ( Event $event ): bool => str_contains( (string) $event->command, 'pagespeed:monitor' ),
    ) );
}
