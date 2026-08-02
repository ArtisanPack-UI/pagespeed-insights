<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config( [
        'pagespeed-insights.retention.days'          => 365,
        'pagespeed-insights.retention.keep_raw_days' => 30,
    ] );
} );

it( 'deletes expired results and reports how many', function (): void {
    psiPrunableResult( 400 );
    psiPrunableResult( 10 );

    $this->artisan( 'pagespeed:prune' )
        ->expectsOutputToContain( 'Deleted 1 result(s) older than 365 day(s).' )
        ->assertSuccessful();

    expect( PageSpeedResult::query()->count() )->toBe( 1 );
} );

it( 'discards stale raw payloads and reports how many', function (): void {
    $stale = psiPrunableResult( 60, raw: true );

    $this->artisan( 'pagespeed:prune' )
        ->expectsOutputToContain( 'Discarded the raw payload on 1 result(s) older than 30 day(s).' )
        ->assertSuccessful();

    expect( $stale->refresh()->raw_response )->toBeNull()
        ->and( PageSpeedResult::query()->count() )->toBe( 1 );
} );

it( 'applies both windows in one run', function (): void {
    $expired = psiPrunableResult( 400, raw: true );
    $stale   = psiPrunableResult( 60, raw: true );
    $fresh   = psiPrunableResult( 10, raw: true );

    $this->artisan( 'pagespeed:prune' )->assertSuccessful();

    expect( PageSpeedResult::query()->find( $expired->id ) )->toBeNull()
        ->and( $stale->refresh()->raw_response )->toBeNull()
        ->and( $fresh->refresh()->raw_response )->not->toBeNull();
} );

it( 'succeeds with a zero count when nothing has expired', function (): void {
    psiPrunableResult( 10, raw: true );

    $this->artisan( 'pagespeed:prune' )
        ->expectsOutputToContain( 'Deleted 0 result(s)' )
        ->assertSuccessful();

    expect( PageSpeedResult::query()->count() )->toBe( 1 );
} );

it( 'says which window is turned off rather than staying silent about it', function (): void {
    config( [ 'pagespeed-insights.retention.days' => 0 ] );

    $this->artisan( 'pagespeed:prune' )
        ->expectsOutputToContain( 'Results are never deleted' )
        ->assertSuccessful();
} );

it( 'warns and does nothing when both windows are turned off', function (): void {
    config( [
        'pagespeed-insights.retention.days'          => 0,
        'pagespeed-insights.retention.keep_raw_days' => 0,
    ] );

    $old = psiPrunableResult( 4000, raw: true );

    $this->artisan( 'pagespeed:prune' )
        ->expectsOutputToContain( 'Both retention windows are turned off' )
        ->assertSuccessful();

    expect( PageSpeedResult::query()->count() )->toBe( 1 )
        ->and( $old->refresh()->raw_response )->not->toBeNull();
} );

describe( 'the --dry-run option', function (): void {
    it( 'reports what would go without touching anything', function (): void {
        $expired = psiPrunableResult( 400 );
        $stale   = psiPrunableResult( 60, raw: true );

        $this->artisan( 'pagespeed:prune', [ '--dry-run' => true ] )
            ->expectsOutputToContain( 'Would delete 1 result(s) older than 365 day(s).' )
            ->expectsOutputToContain( 'Would discard the raw payload on 1 result(s) older than 30 day(s).' )
            ->assertSuccessful();

        expect( PageSpeedResult::query()->find( $expired->id ) )->not->toBeNull()
            ->and( $stale->refresh()->raw_response )->not->toBeNull();
    } );

    it( 'does not promise to strip payloads off rows it would delete outright', function (): void {
        psiPrunableResult( 400, raw: true );

        $this->artisan( 'pagespeed:prune', [ '--dry-run' => true ] )
            ->expectsOutputToContain( 'Would delete 1 result(s)' )
            ->expectsOutputToContain( 'Would discard the raw payload on 0 result(s)' )
            ->assertSuccessful();
    } );
} );

describe( 'the scheduled task', function (): void {
    it( 'runs daily by default', function (): void {
        expect( psiScheduledPruneEvents() )->toHaveCount( 1 )
            ->and( psiScheduledPruneEvents()[ 0 ]->expression )->toBe( '0 0 * * *' );
    } );

    it( 'is not registered when scheduling is disabled', function (): void {
        config( [ 'pagespeed-insights.scheduling.enabled' => false ] );

        expect( psiScheduledPruneEvents() )->toHaveCount( 0 );
    } );
} );

/**
 * The scheduled entries for this package's pruning command.
 *
 * Resolving the scheduler is what registers them, so this must not be called
 * before a test has set the config it means to exercise.
 *
 * @return array<int, Event> The matching scheduled events.
 */
function psiScheduledPruneEvents(): array
{
    return array_values( array_filter(
        app( Schedule::class )->events(),
        static fn ( Event $event ): bool => str_contains( (string) $event->command, 'pagespeed:prune' ),
    ) );
}

/**
 * Store one result written the given number of days ago.
 *
 * Named apart from the retention policy suite's own helper: Pest loads every
 * test file into one process, so two functions of one name are a fatal.
 *
 * @param  int  $daysAgo  How old the row is.
 * @param  bool  $raw  Whether it retained its raw payload.
 *
 * @return PageSpeedResult The stored row.
 */
function psiPrunableResult( int $daysAgo, bool $raw = false ): PageSpeedResult
{
    $factory = PageSpeedResult::factory();

    if ( $raw ) {
        $factory = $factory->withRawResponse();
    }

    return $factory->create( [ 'created_at' => CarbonImmutable::now()->subDays( $daysAgo ) ] );
}
