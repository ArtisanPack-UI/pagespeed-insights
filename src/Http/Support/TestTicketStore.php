<?php

/**
 * Tracks ad hoc runs queued through the HTTP API.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Http\Support;

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;

/**
 * The id `POST /pagespeed/test` hands back, and what `GET /pagespeed/results/{id}`
 * makes of it.
 *
 * ### Why there is a ticket at all
 *
 * A queued run has no result row until it finishes, so there is no result id to
 * return at the moment the caller most needs one. The two obvious ways out are
 * both worse than this:
 *
 * - **Write a pending row.** It would give the caller a real id immediately,
 *   and it would also put a third status into a table every other reader of
 *   which assumes two. `PageSpeedResult::latestFor()` — which the score card,
 *   the vitals card, and the opportunities table all read — would start
 *   returning a row with no scores in it, and each of those surfaces would
 *   render an in-flight run as a completed one that measured nothing.
 * - **Return the newest existing id and let the client watch for a bigger
 *   one.** That is the mechanism used here, but pushing it into the client
 *   means every consumer reimplements it, and a consumer that gets it slightly
 *   wrong shows a stale result as a fresh one.
 *
 * So the watch is kept server-side. A ticket records what was queued and which
 * result id was newest at the time; polling it looks for a newer row for the
 * same URL and form factor. A run finishing is simply a row appearing, which
 * means there is no separate "is it done" channel that could disagree with the
 * database.
 *
 * ### The timeout is a real answer
 *
 * A ticket that never resolves renders a dead queue worker as a slow one, which
 * is the same misreading this package spends most of its design effort
 * avoiding. Past {@see self::TIMEOUT_SECONDS} the ticket reports `timed_out` —
 * not `failed`, because the run may well still be queued — and says so in a
 * message that names the thing worth checking.
 *
 * The window sits above the job's own worst case rather than above a typical
 * run: a PageSpeed run takes 20-60 seconds, but the job retries twice on a
 * transient failure with a 60 and then 300 second backoff, so a run that is
 * genuinely still coming can legitimately take several minutes.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class TestTicketStore
{
    /**
     * The cache key prefix every ticket is held under.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY_PREFIX = 'pagespeed-insights:test-ticket:';

    /**
     * Seconds a ticket is honoured for before it reports as timed out.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const TIMEOUT_SECONDS = 600;

    /**
     * The run has not produced a row yet.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_QUEUED = 'queued';

    /**
     * The run produced a row and it completed.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * The run produced a row recording a failure.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_FAILED = 'failed';

    /**
     * The run has not reported back inside {@see self::TIMEOUT_SECONDS}.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_TIMED_OUT = 'timed-out';

    /**
     * Build the store.
     *
     * @since 1.0.0
     *
     * @param  CacheRepository  $cache  Where tickets are held.
     */
    public function __construct( protected CacheRepository $cache )
    {
    }

    /**
     * Record a queued run and return the id to poll it with.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The canonical URL being tested.
     * @param  string  $strategy  The form factor being tested.
     *
     * @return string The ticket id.
     */
    public function open( string $url, string $strategy ): string
    {
        $id = (string) Str::uuid();

        $this->cache->put(
            self::KEY_PREFIX . $id,
            [
                'url'      => $url,
                'strategy' => $strategy,
                // The newest row at the moment of queueing. Anything above it
                // for this URL and form factor is this run's doing.
                'sinceId'  => $this->newestResultId( $url, $strategy ),
                'queuedAt' => CarbonImmutable::now()->getTimestamp(),
            ],
            // Kept a little past the timeout so that a caller polling at the
            // boundary is told the run timed out rather than that their ticket
            // never existed.
            self::TIMEOUT_SECONDS * 2,
        );

        return $id;
    }

    /**
     * What has become of a ticket.
     *
     * @since 1.0.0
     *
     * @param  string  $id  The ticket id.
     *
     * @return array{status: string, url: string, strategy: string, result: PageSpeedResult|null}|null The ticket's state, or null when there is no such ticket.
     */
    public function poll( string $id ): ?array
    {
        $ticket = $this->cache->get( self::KEY_PREFIX . $id );

        if ( ! is_array( $ticket ) || ! isset( $ticket[ 'url' ], $ticket[ 'strategy' ] ) ) {
            return null;
        }

        $url      = (string) $ticket[ 'url' ];
        $strategy = (string) $ticket[ 'strategy' ];
        $result   = $this->resultSince(
            $url,
            $strategy,
            is_numeric( $ticket[ 'sinceId' ] ?? null ) ? (int) $ticket[ 'sinceId' ] : null,
        );

        if ( null !== $result ) {
            // The ticket is deliberately left in place rather than forgotten
            // here. Polling is not a one-shot read: a client that fetches
            // twice — a retry, a component that mounts twice, two tabs — must
            // get the same answer both times rather than a 404 for a run it
            // has already been told completed. The lookup is deterministic
            // (the first row after `sinceId`, by id) so every subsequent poll
            // resolves to the same row until the ticket expires on its own.
            return [
                'status'   => $result->isFailed() ? self::STATUS_FAILED : self::STATUS_COMPLETED,
                'url'      => $url,
                'strategy' => $strategy,
                'result'   => $result,
            ];
        }

        $queuedAt = is_numeric( $ticket[ 'queuedAt' ] ?? null ) ? (int) $ticket[ 'queuedAt' ] : 0;
        $waited   = CarbonImmutable::now()->getTimestamp() - $queuedAt;

        return [
            'status'   => $waited >= self::TIMEOUT_SECONDS ? self::STATUS_TIMED_OUT : self::STATUS_QUEUED,
            'url'      => $url,
            'strategy' => $strategy,
            'result'   => null,
        ];
    }

    /**
     * The id of the newest stored run for one URL and form factor.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The canonical URL.
     * @param  string  $strategy  The form factor.
     *
     * @return int|null The id, or null when there is no history.
     */
    protected function newestResultId( string $url, string $strategy ): ?int
    {
        $id = PageSpeedResult::query()
            ->forUrl( $url, $strategy )
            ->max( 'id' );

        return is_numeric( $id ) ? (int) $id : null;
    }

    /**
     * The first run stored for one URL and form factor after a given id.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The canonical URL.
     * @param  string  $strategy  The form factor.
     * @param  int|null  $sinceId  The newest id at the time the run was queued.
     *
     * @return PageSpeedResult|null The row this run produced, or null when it has not landed.
     */
    protected function resultSince( string $url, string $strategy, ?int $sinceId ): ?PageSpeedResult
    {
        $query = PageSpeedResult::query()->forUrl( $url, $strategy );

        if ( null !== $sinceId ) {
            $query->where( 'id', '>', $sinceId );
        }

        return $query->orderBy( 'id' )->first();
    }
}
