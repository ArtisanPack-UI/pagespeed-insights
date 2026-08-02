<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Support\RetentionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config( [
        'pagespeed-insights.retention.days'          => 365,
        'pagespeed-insights.retention.keep_raw_days' => 30,
    ] );
} );

describe( 'the configured windows', function (): void {
    it( 'defaults to a year of history and a month of raw payloads', function (): void {
        config( [ 'pagespeed-insights.retention' => null ] );

        expect( psiRetention()->days() )->toBe( RetentionPolicy::DEFAULT_DAYS )
            ->and( psiRetention()->keepRawDays() )->toBe( RetentionPolicy::DEFAULT_KEEP_RAW_DAYS );
    } );

    it( 'reads a window that is turned off as off rather than as a cutoff in the future', function ( mixed $value ): void {
        config( [ 'pagespeed-insights.retention.days' => $value ] );

        expect( psiRetention()->days() )->toBeNull()
            ->and( psiRetention()->resultCutoff() )->toBeNull();
    } )->with( [
        'zero'       => [ 0 ],
        'negative'   => [ -30 ],
        'null'       => [ null ],
        'blank'      => [ '' ],
        'nonsense'   => [ 'forever' ],
        'an array'   => [ [ 365 ] ],
        'a boolean'  => [ true ],
    ] );

    it( 'reads a window past the cap as off rather than overflowing into a cutoff in the future', function ( mixed $value ): void {
        config( [ 'pagespeed-insights.retention.days' => $value ] );

        psiAgedResult( 1 );

        expect( psiRetention()->days() )->toBeNull()
            ->and( psiRetention()->resultCutoff() )->toBeNull()
            ->and( psiRetention()->deleteExpiredResults() )->toBe( 0 )
            ->and( PageSpeedResult::query()->count() )->toBe( 1 );
    } )->with( [
        'past the cap'      => [ RetentionPolicy::MAX_DAYS + 1 ],
        'exponential'       => [ '1e20' ],
        'the integer limit' => [ PHP_INT_MAX ],
    ] );

    it( 'accepts a window on the cap itself', function (): void {
        config( [ 'pagespeed-insights.retention.days' => RetentionPolicy::MAX_DAYS ] );

        expect( psiRetention()->days() )->toBe( RetentionPolicy::MAX_DAYS )
            ->and( psiRetention()->resultCutoff()->isPast() )->toBeTrue();
    } );

    it( 'accepts a numeric string, which is what an env var gives it', function (): void {
        config( [ 'pagespeed-insights.retention.days' => '90' ] );

        expect( psiRetention()->days() )->toBe( 90 );
    } );

    it( 'measures each cutoff back from now', function (): void {
        CarbonImmutable::setTestNow( '2026-08-02 12:00:00' );

        expect( psiRetention()->resultCutoff()->toDateTimeString() )->toBe( '2025-08-02 12:00:00' )
            ->and( psiRetention()->rawResponseCutoff()->toDateTimeString() )->toBe( '2026-07-03 12:00:00' );

        CarbonImmutable::setTestNow();
    } );
} );

describe( 'the deletion window', function (): void {
    it( 'deletes results older than it and keeps the rest', function (): void {
        $expired = psiAgedResult( 400 );
        $kept    = psiAgedResult( 300 );

        expect( psiRetention()->deleteExpiredResults() )->toBe( 1 )
            ->and( PageSpeedResult::query()->pluck( 'id' )->all() )->toBe( [ $kept->id ] )
            ->and( PageSpeedResult::query()->find( $expired->id ) )->toBeNull();
    } );

    it( 'keeps a result on the boundary itself', function (): void {
        psiAgedResult( 365 );

        expect( psiRetention()->deleteExpiredResults() )->toBe( 0 )
            ->and( PageSpeedResult::query()->count() )->toBe( 1 );
    } );

    it( 'deletes failed rows too, which have no fetched_at to age by', function (): void {
        PageSpeedResult::factory()->failed()->create( [
            'created_at' => CarbonImmutable::now()->subDays( 400 ),
            'fetched_at' => null,
        ] );

        expect( psiRetention()->deleteExpiredResults() )->toBe( 1 )
            ->and( PageSpeedResult::query()->count() )->toBe( 0 );
    } );

    it( 'deletes nothing when the window is turned off', function (): void {
        config( [ 'pagespeed-insights.retention.days' => 0 ] );

        psiAgedResult( 4000 );

        expect( psiRetention()->deleteExpiredResults() )->toBe( 0 )
            ->and( psiRetention()->expiredResults()->count() )->toBe( 0 )
            ->and( PageSpeedResult::query()->count() )->toBe( 1 );
    } );

    it( 'works through more rows than one chunk holds', function (): void {
        psiInsertAgedResults( RetentionPolicy::CHUNK_SIZE + 250, 400 );
        psiInsertAgedResults( 5, 10 );

        expect( psiRetention()->deleteExpiredResults() )->toBe( RetentionPolicy::CHUNK_SIZE + 250 )
            ->and( PageSpeedResult::query()->count() )->toBe( 5 );
    } );
} );

describe( 'the raw payload window', function (): void {
    it( 'discards a payload older than it while keeping the row and its scores', function (): void {
        $stale = psiAgedResult( 60, raw: true );

        expect( psiRetention()->stripStaleRawResponses() )->toBe( 1 );

        $stale->refresh();

        expect( $stale->raw_response )->toBeNull()
            ->and( $stale->performance_score )->not->toBeNull()
            ->and( PageSpeedResult::query()->count() )->toBe( 1 );
    } );

    it( 'leaves a payload inside the window alone', function (): void {
        $fresh = psiAgedResult( 10, raw: true );

        expect( psiRetention()->stripStaleRawResponses() )->toBe( 0 )
            ->and( $fresh->refresh()->raw_response )->not->toBeNull();
    } );

    it( 'ignores rows that never stored a payload', function (): void {
        psiAgedResult( 60 );

        expect( psiRetention()->staleRawResponses()->count() )->toBe( 0 )
            ->and( psiRetention()->stripStaleRawResponses() )->toBe( 0 );
    } );

    it( 'discards nothing when the window is turned off', function (): void {
        config( [ 'pagespeed-insights.retention.keep_raw_days' => 0 ] );

        $stale = psiAgedResult( 4000, raw: true );

        expect( psiRetention()->stripStaleRawResponses() )->toBe( 0 )
            ->and( psiRetention()->staleRawResponses()->count() )->toBe( 0 )
            ->and( $stale->refresh()->raw_response )->not->toBeNull();
    } );

    it( 'does not count payloads on rows the deletion window is about to take', function (): void {
        psiAgedResult( 400, raw: true );

        expect( psiRetention()->staleRawResponses()->count() )->toBe( 0 );
    } );

    it( 'works through more rows than one chunk holds', function (): void {
        psiInsertAgedResults( RetentionPolicy::CHUNK_SIZE + 250, 60, raw: true );

        expect( psiRetention()->stripStaleRawResponses() )->toBe( RetentionPolicy::CHUNK_SIZE + 250 )
            ->and( PageSpeedResult::query()->whereNotNull( 'raw_response' )->count() )->toBe( 0 );
    } );
} );

describe( 'the two windows together', function (): void {
    it( 'applies each one independently', function (): void {
        $expired = psiAgedResult( 400, raw: true );
        $stale   = psiAgedResult( 60, raw: true );
        $fresh   = psiAgedResult( 10, raw: true );

        expect( psiRetention()->apply() )->toBe( [ 'deleted' => 1, 'stripped' => 1 ] )
            ->and( PageSpeedResult::query()->find( $expired->id ) )->toBeNull()
            ->and( $stale->refresh()->raw_response )->toBeNull()
            ->and( $fresh->refresh()->raw_response )->not->toBeNull();
    } );

    it( 'strips without deleting when only the payload window is on', function (): void {
        config( [ 'pagespeed-insights.retention.days' => 0 ] );

        $old = psiAgedResult( 4000, raw: true );

        expect( psiRetention()->apply() )->toBe( [ 'deleted' => 0, 'stripped' => 1 ] )
            ->and( PageSpeedResult::query()->count() )->toBe( 1 )
            ->and( $old->refresh()->raw_response )->toBeNull();
    } );

    it( 'deletes without stripping when only the deletion window is on', function (): void {
        config( [ 'pagespeed-insights.retention.keep_raw_days' => 0 ] );

        psiAgedResult( 400, raw: true );
        $kept = psiAgedResult( 60, raw: true );

        expect( psiRetention()->apply() )->toBe( [ 'deleted' => 1, 'stripped' => 0 ] )
            ->and( $kept->refresh()->raw_response )->not->toBeNull();
    } );

    it( 'does nothing at all when both windows are off', function (): void {
        config( [
            'pagespeed-insights.retention.days'          => 0,
            'pagespeed-insights.retention.keep_raw_days' => 0,
        ] );

        $old = psiAgedResult( 4000, raw: true );

        expect( psiRetention()->apply() )->toBe( [ 'deleted' => 0, 'stripped' => 0 ] )
            ->and( $old->refresh()->raw_response )->not->toBeNull();
    } );
} );

describe( 'Prunable', function (): void {
    it( 'exposes the expired results to model:prune', function (): void {
        $expired = psiAgedResult( 400 );
        psiAgedResult( 10 );

        expect( ( new PageSpeedResult() )->prunable()->pluck( 'id' )->all() )->toBe( [ $expired->id ] );
    } );

    it( 'prunes nothing when the window is turned off', function (): void {
        config( [ 'pagespeed-insights.retention.days' => 0 ] );

        psiAgedResult( 4000 );

        expect( ( new PageSpeedResult() )->pruneAll() )->toBe( 0 )
            ->and( PageSpeedResult::query()->count() )->toBe( 1 );
    } );

    it( 'deletes the expired results when pruned', function (): void {
        psiAgedResult( 400 );
        psiAgedResult( 10 );

        expect( ( new PageSpeedResult() )->pruneAll() )->toBe( 1 )
            ->and( PageSpeedResult::query()->count() )->toBe( 1 );
    } );
} );

/**
 * The retention policy, resolved fresh so it reads the current config.
 *
 * @return RetentionPolicy The policy.
 */
function psiRetention(): RetentionPolicy
{
    return app( RetentionPolicy::class );
}

/**
 * Store one result written the given number of days ago.
 *
 * @param  int  $daysAgo  How old the row is.
 * @param  bool  $raw  Whether it retained its raw payload.
 *
 * @return PageSpeedResult The stored row.
 */
function psiAgedResult( int $daysAgo, bool $raw = false ): PageSpeedResult
{
    $factory = PageSpeedResult::factory();

    if ( $raw ) {
        $factory = $factory->withRawResponse();
    }

    return $factory->create( [ 'created_at' => CarbonImmutable::now()->subDays( $daysAgo ) ] );
}

/**
 * Store many results of the same age, cheaply.
 *
 * Inserted rather than made through the factory because the chunking tests
 * need more rows than one chunk holds and none of them care what is in a row
 * beyond its age and whether it carries a payload.
 *
 * @param  int  $count  How many rows to write.
 * @param  int  $daysAgo  How old they are.
 * @param  bool  $raw  Whether they retained a raw payload.
 *
 * @return void
 */
function psiInsertAgedResults( int $count, int $daysAgo, bool $raw = false ): void
{
    $created = CarbonImmutable::now()->subDays( $daysAgo );
    $rows    = [];

    for ( $index = 0; $index < $count; $index++ ) {
        $rows[] = [
            'url'          => 'https://example.com/page-' . $index,
            'strategy'     => 'mobile',
            'status'       => PageSpeedResult::STATUS_COMPLETED,
            'raw_response' => $raw ? '{"id":"https://example.com"}' : null,
            'created_at'   => $created,
            'updated_at'   => $created,
        ];
    }

    foreach ( array_chunk( $rows, 500 ) as $chunk ) {
        PageSpeedResult::query()->insert( $chunk );
    }
}
