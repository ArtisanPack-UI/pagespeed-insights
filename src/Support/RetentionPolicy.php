<?php

/**
 * How long stored PageSpeed history is kept.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Support;

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * The two retention windows on `pagespeed_results`, and the work of applying
 * them.
 *
 * Two windows rather than one because the two things a result row holds cost
 * wildly different amounts to keep. The scores are a few dozen bytes and are
 * the entire reason history exists; a retained `raw_response` is hundreds of
 * kilobytes of data the package has already parsed into its own columns, kept
 * only so a parsing problem can be diagnosed against a real payload. So the
 * payload expires first and the row outlives it, which is what stops the
 * table growing by two orders of magnitude for the sake of a debugging aid
 * nobody is going to read a year from now.
 *
 * Both windows measure from `created_at` — when the row was written — rather
 * than `fetched_at`, which is Google's own analysis timestamp and is null on
 * a failed run. Retention has to be able to expire a failed row too.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class RetentionPolicy
{
    /**
     * Days of history kept when nothing is configured.
     *
     * A full year, so that this year's Black Friday has last year's to be
     * compared against.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_DAYS = 365;

    /**
     * Days a retained raw payload is kept when nothing is configured.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_KEEP_RAW_DAYS = 30;

    /**
     * The largest window that is read as a window at all.
     *
     * A century, which no install has a use for and every plausible typo
     * clears. The cap is not tidiness: `subDays()` on a day count near
     * `PHP_INT_MAX` overflows and lands the cutoff **in the future**, and a
     * cutoff in the future matches every row in the table. So a value this
     * far out is read as "off" rather than trusted, which is the difference
     * between a fat-fingered env var doing nothing and a fat-fingered env var
     * deleting all of the history.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_DAYS = 36500;

    /**
     * Rows touched by one statement.
     *
     * Chunked rather than issued as one unbounded `delete` because the first
     * prune of a table that has been growing for a year is exactly the case
     * this command exists for, and a single statement over a few million rows
     * is a long-held lock on the table every write path needs.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const CHUNK_SIZE = 1000;

    /**
     * Construct the policy.
     *
     * @since 1.0.0
     *
     * @param  ConfigRepository  $config  Supplies the configured windows.
     */
    public function __construct( protected ConfigRepository $config )
    {
    }

    /**
     * How many days of history are kept.
     *
     * @since 1.0.0
     *
     * @return int|null The window, or null when history is never deleted.
     */
    public function days(): ?int
    {
        return $this->window( 'days', self::DEFAULT_DAYS );
    }

    /**
     * How many days a retained raw payload is kept for.
     *
     * @since 1.0.0
     *
     * @return int|null The window, or null when payloads are never stripped.
     */
    public function keepRawDays(): ?int
    {
        return $this->window( 'keep_raw_days', self::DEFAULT_KEEP_RAW_DAYS );
    }

    /**
     * The moment before which a result is deleted.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable|null The cutoff, or null when history is never deleted.
     */
    public function resultCutoff(): ?CarbonImmutable
    {
        $days = $this->days();

        return null === $days ? null : CarbonImmutable::now()->subDays( $days );
    }

    /**
     * The moment before which a result's raw payload is discarded.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable|null The cutoff, or null when payloads are never stripped.
     */
    public function rawResponseCutoff(): ?CarbonImmutable
    {
        $days = $this->keepRawDays();

        return null === $days ? null : CarbonImmutable::now()->subDays( $days );
    }

    /**
     * The results that have outlived the retention window.
     *
     * Always a real query so callers can count it, chunk it, or hand it to
     * Laravel's `model:prune`. When retention is off it is one that matches
     * nothing rather than one that matches everything, which is the direction
     * a mistake here has to fail in.
     *
     * @since 1.0.0
     *
     * @return Builder<PageSpeedResult> The expired results.
     */
    public function expiredResults(): Builder
    {
        $cutoff = $this->resultCutoff();

        if ( null === $cutoff ) {
            return PageSpeedResult::query()->whereRaw( '1 = 0' );
        }

        return PageSpeedResult::query()->where( 'created_at', '<', $cutoff );
    }

    /**
     * The results still inside the retention window whose raw payload is not.
     *
     * Rows the deletion window has already claimed are excluded rather than
     * counted twice. {@see self::apply()} deletes before it strips, so this
     * only changes what a preview reports — but a preview that promises to
     * strip payloads off ten thousand rows it is about to delete outright is
     * a preview of something that will not happen.
     *
     * @since 1.0.0
     *
     * @return Builder<PageSpeedResult> The results with a stale payload.
     */
    public function staleRawResponses(): Builder
    {
        $cutoff = $this->rawResponseCutoff();

        if ( null === $cutoff ) {
            return PageSpeedResult::query()->whereRaw( '1 = 0' );
        }

        $query = PageSpeedResult::query()
            ->where( 'created_at', '<', $cutoff )
            ->whereNotNull( 'raw_response' );

        $deletionCutoff = $this->resultCutoff();

        if ( null !== $deletionCutoff ) {
            $query->where( 'created_at', '>=', $deletionCutoff );
        }

        return $query;
    }

    /**
     * Apply both windows.
     *
     * Deletion runs first so that stripping does not spend work on payloads
     * belonging to rows that are about to go — which is most of them whenever
     * the two windows are close together.
     *
     * @since 1.0.0
     *
     * @return array{deleted: int, stripped: int} How many rows each window accounted for.
     */
    public function apply(): array
    {
        return [
            'deleted'  => $this->deleteExpiredResults(),
            'stripped' => $this->stripStaleRawResponses(),
        ];
    }

    /**
     * Delete the results that have outlived the retention window.
     *
     * @since 1.0.0
     *
     * @return int How many rows were deleted.
     */
    public function deleteExpiredResults(): int
    {
        if ( null === $this->resultCutoff() ) {
            return 0;
        }

        return $this->inChunks(
            fn (): Builder => $this->expiredResults(),
            static fn ( Builder $chunk ): int => $chunk->delete(),
        );
    }

    /**
     * Discard the raw payloads that have outlived their own window.
     *
     * @since 1.0.0
     *
     * @return int How many rows had a payload discarded.
     */
    public function stripStaleRawResponses(): int
    {
        if ( null === $this->rawResponseCutoff() ) {
            return 0;
        }

        return $this->inChunks(
            fn (): Builder => $this->staleRawResponses(),
            static fn ( Builder $chunk ): int => $chunk->update( [ 'raw_response' => null ] ),
        );
    }

    /**
     * Work through a query a chunk at a time until it stops matching rows.
     *
     * The ids are read first and the statement is then keyed on them, rather
     * than issuing `delete ... limit`, because a bounded delete or update is
     * a portability trap: MySQL takes it, SQLite only takes it when compiled
     * with an option most builds leave off, and Postgres does not take it at
     * all. Both callers narrow the set they match on — a deleted row is gone,
     * a nulled payload no longer satisfies `whereNotNull` — so the loop
     * always terminates.
     *
     * @since 1.0.0
     *
     * @param  callable(): Builder<PageSpeedResult>  $query  Builds a fresh query over the remaining rows.
     * @param  callable(Builder<PageSpeedResult>): int  $apply  Performs the statement, returning rows affected.
     *
     * @return int How many rows were affected in total.
     */
    protected function inChunks( callable $query, callable $apply ): int
    {
        $total = 0;

        do {
            $ids = $query()
                ->orderBy( 'id' )
                ->limit( self::CHUNK_SIZE )
                ->pluck( 'id' )
                ->all();

            if ( [] === $ids ) {
                break;
            }

            $affected = $apply( PageSpeedResult::query()->whereIn( 'id', $ids ) );

            $total += $affected;

            // A chunk that matched rows and changed none of them would
            // otherwise be selected again forever.
            if ( 0 === $affected ) {
                break;
            }
        } while ( self::CHUNK_SIZE === count( $ids ) );

        return $total;
    }

    /**
     * Read one configured window.
     *
     * A window has to be a positive whole number of days no larger than
     * {@see self::MAX_DAYS} to mean anything; anything else — 0, a negative,
     * a blank env var, a typo, a value so large that `subDays()` overflows
     * past it — is read as "off". Off is the safe reading in every one of
     * those cases: the alternative is a cutoff in the future, and a cutoff in
     * the future matches the entire table.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The key under `pagespeed-insights.retention`.
     * @param  int  $default  The window used when the key is absent.
     *
     * @return int|null The window in days, or null when it is turned off.
     */
    protected function window( string $key, int $default ): ?int
    {
        $value = $this->config->get( 'pagespeed-insights.retention.' . $key, $default );

        if ( ! is_numeric( $value ) ) {
            return null;
        }

        $days = (int) $value;

        return $days > 0 && $days <= self::MAX_DAYS ? $days : null;
    }
}
