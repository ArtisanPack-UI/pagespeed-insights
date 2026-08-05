<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Scheduling\TestScheduler;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config( [ 'pagespeed-insights.api_key' => 'test-key' ] );

    Queue::fake();
} );

/**
 * The URLs and strategies that were queued, as "url|strategy" pairs.
 *
 * @return array<int, string> The dispatched runs, sorted.
 */
function psiQueuedRuns(): array
{
    $runs = Queue::pushed( RunPageSpeedTest::class )
        ->map( static fn ( RunPageSpeedTest $job ): string => $job->url . '|' . $job->strategy )
        ->all();

    sort( $runs );

    return $runs;
}

describe( 'dispatching what is due', function (): void {
    it( 'queues one job per due URL per strategy', function (): void {
        PageSpeedUrl::factory()->neverTested()->create( [ 'url' => 'https://example.com/about' ] );
        PageSpeedUrl::factory()->neverTested()->strategy( PageSpeedRequest::STRATEGY_MOBILE )
            ->create( [ 'url' => 'https://example.com/pricing' ] );

        $report = app( TestScheduler::class )->dispatchDue();

        expect( $report )->toBe( [ 'urls' => 2, 'jobs' => 3 ] )
            ->and( psiQueuedRuns() )->toBe( [
                'https://example.com/about|desktop',
                'https://example.com/about|mobile',
                'https://example.com/pricing|mobile',
            ] );
    } );

    it( 'skips URLs that are not yet owed a run', function (): void {
        PageSpeedUrl::factory()->notDue( 'weekly' )->create( [ 'url' => 'https://example.com/about' ] );

        expect( app( TestScheduler::class )->dispatchDue() )->toBe( [ 'urls' => 0, 'jobs' => 0 ] );

        Queue::assertNothingPushed();
    } );

    it( 'honours a per-URL frequency override', function (): void {
        PageSpeedUrl::factory()->due( 'hourly' )->create( [ 'url' => 'https://example.com/hourly' ] );
        PageSpeedUrl::factory()->notDue( 'monthly' )->create( [ 'url' => 'https://example.com/monthly' ] );

        app( TestScheduler::class )->dispatchDue();

        expect( psiQueuedRuns() )->toBe( [
            'https://example.com/hourly|desktop',
            'https://example.com/hourly|mobile',
        ] );
    } );

    it( 'skips paused URLs', function (): void {
        PageSpeedUrl::factory()->inactive()->neverTested()->create( [ 'url' => 'https://example.com/about' ] );

        app( TestScheduler::class )->dispatchDue();

        Queue::assertNothingPushed();
    } );

    it( 'does not re-queue a URL whose run has not come back yet', function (): void {
        // The shape of a quota outage: nothing completes, so nothing stamps
        // `last_tested_at`, and every tick would otherwise re-dispatch the
        // whole due set and accumulate one duplicate per URL per tick.
        PageSpeedUrl::factory()->neverTested()->strategy()->create( [ 'url' => 'https://example.com/about' ] );

        expect( app( TestScheduler::class )->dispatchDue() )->toBe( [ 'urls' => 1, 'jobs' => 1 ] )
            ->and( app( TestScheduler::class )->dispatchDue() )->toBe( [ 'urls' => 0, 'jobs' => 0 ] )
            ->and( app( TestScheduler::class )->dispatchDue() )->toBe( [ 'urls' => 0, 'jobs' => 0 ] );

        expect( psiQueuedRuns() )->toBe( [ 'https://example.com/about|mobile' ] );
    } );

    it( 'records when a URL was dispatched', function (): void {
        $url = PageSpeedUrl::factory()->neverTested()->create( [ 'url' => 'https://example.com/about' ] );

        expect( $url->last_dispatched_at )->toBeNull();

        app( TestScheduler::class )->dispatchDue();

        expect( $url->fresh()->last_dispatched_at )->not->toBeNull()
            ->and( $url->fresh()->last_tested_at )->toBeNull();
    } );

    it( 'queues a URL again once its run can no longer be alive', function (): void {
        PageSpeedUrl::factory()->neverTested()->strategy()->create( [ 'url' => 'https://example.com/about' ] );

        app( TestScheduler::class )->dispatchDue();

        // A genuinely lost job must not strand the URL forever, so the window
        // ends where the job's own retry deadline does.
        $this->travel( RunPageSpeedTest::maximumLifetimeSeconds() + 60 )->seconds();

        expect( app( TestScheduler::class )->dispatchDue() )->toBe( [ 'urls' => 1, 'jobs' => 1 ] );
    } );

    it( 'still dispatches a URL an operator asked for by hand', function (): void {
        $url = PageSpeedUrl::factory()->neverTested()->strategy()->create( [ 'url' => 'https://example.com/about' ] );

        app( TestScheduler::class )->dispatchFor( $url );

        // Due-ness is not consulted on this path, and neither is in-flightness:
        // the operator has already decided the run should happen.
        expect( app( TestScheduler::class )->dispatchFor( $url->fresh() ) )->toBe( [ 'urls' => 1, 'jobs' => 1 ] );

        Queue::assertPushed( RunPageSpeedTest::class, 2 );
    } );

    it( 'carries the monitored row id onto each job', function (): void {
        $url = PageSpeedUrl::factory()->neverTested()->strategy()->create( [ 'url' => 'https://example.com/about' ] );

        app( TestScheduler::class )->dispatchDue();

        Queue::assertPushed(
            RunPageSpeedTest::class,
            static fn ( RunPageSpeedTest $job ): bool => $job->monitoredUrlId === $url->getKey(),
        );
    } );
} );

describe( 'the credential preflight', function (): void {
    it( 'refuses the whole cycle when no API key is configured', function (): void {
        config( [ 'pagespeed-insights.api_key' => null ] );

        PageSpeedUrl::factory()->count( 3 )->neverTested()->create();

        expect( app( TestScheduler::class )->dispatchDue() )->toBe( [ 'urls' => 0, 'jobs' => 0 ] );

        Queue::assertNothingPushed();
    } );

    it( 'reports whether a cycle could run at all', function (): void {
        expect( app( TestScheduler::class )->hasCredentials() )->toBeTrue();

        config( [ 'pagespeed-insights.api_key' => null ] );

        expect( app( TestScheduler::class )->hasCredentials() )->toBeFalse();
    } );
} );

describe( 'hook-contributed URLs', function (): void {
    it( 'persists them so they gain a cadence and history', function (): void {
        addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => array_merge(
            $urls,
            [ 'https://example.com/checkout' ],
        ) );

        app( TestScheduler::class )->dispatchDue();

        $stored = PageSpeedUrl::query()->forUrl( 'https://example.com/checkout' )->sole();

        expect( $stored->source )->toBe( PageSpeedUrl::SOURCE_HOOK )
            ->and( psiQueuedRuns() )->toBe( [
                'https://example.com/checkout|desktop',
                'https://example.com/checkout|mobile',
            ] );
    } );

    it( 'leaves them unsaved when persisting is turned off', function (): void {
        config( [ 'pagespeed-insights.scheduling.persist_hook_urls' => false ] );

        addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => array_merge(
            $urls,
            [ 'https://example.com/checkout' ],
        ) );

        app( TestScheduler::class )->dispatchDue();

        expect( PageSpeedUrl::query()->count() )->toBe( 0 );

        Queue::assertNothingPushed();
    } );
} );

describe( 'dispatching one URL', function (): void {
    it( 'queues a run whether or not the URL is due', function (): void {
        $url = PageSpeedUrl::factory()->notDue( 'monthly' )->strategy()->create( [ 'url' => 'https://example.com/about' ] );

        expect( app( TestScheduler::class )->dispatchFor( $url ) )->toBe( [ 'urls' => 1, 'jobs' => 1 ] )
            ->and( psiQueuedRuns() )->toBe( [ 'https://example.com/about|mobile' ] );
    } );

    it( 'still refuses without an API key', function (): void {
        config( [ 'pagespeed-insights.api_key' => null ] );

        $url = PageSpeedUrl::factory()->create();

        expect( app( TestScheduler::class )->dispatchFor( $url ) )->toBe( [ 'urls' => 0, 'jobs' => 0 ] );

        Queue::assertNothingPushed();
    } );
} );
