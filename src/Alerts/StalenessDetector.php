<?php

/**
 * Staleness detection for monitored URLs that stop reporting.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Alerts;

use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Notifications\StaleUrlNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds monitored URLs that have gone quiet, and says why they probably did.
 *
 * ### Why this is not the regression detector's job
 *
 * {@see RegressionDetector} compares a new run with the one before it, so it
 * only ever runs when a new run exists. Every failure that *stops* runs
 * happening — a revoked key, an exhausted quota, a dead queue worker, a URL
 * that started returning 404, `scheduling.enabled` switched off in an
 * environment nobody checks — produces no new result, nothing to compare, and
 * therefore no alert. The trend chart flatlines at the last good score and
 * looks perfectly healthy.
 *
 * "No data" cannot be caught by a detector that only runs when data arrives,
 * so this one runs on the schedule instead of after a result is stored.
 *
 * ### What counts as stale
 *
 * An active URL with no **completed** run since its own cadence, times
 * `alerts.staleness.missed_cycles`, elapsed. Tolerance is expressed in missed
 * cycles rather than a flat duration so it scales with each URL: an hourly
 * page is late after two hours, a monthly one after two months, from the same
 * setting.
 *
 * A URL that has never completed a run is measured from when it was added, so
 * a page registered a minute ago is new rather than stale — while one added
 * last month and never successfully tested, which is exactly the broken-key
 * case, still reports.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class StalenessDetector
{
    /**
     * Action fired for each URL found to have stopped reporting.
     *
     * Callbacks receive the {@see PageSpeedUrl} — null for a stale URL whose
     * monitored row has since been deleted, which cannot normally happen —
     * and the diagnosis as an array. Fired for the URLs that alert, so a
     * listener sees them on the same cadence the notification does rather
     * than on every scheduled pass for as long as the URL stays broken.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ACTION_URL_WENT_STALE = 'ap.pageSpeed.urlWentStale';

    /**
     * The cache key prefix marking a URL as already alerted about.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SUPPRESSION_KEY = 'pagespeed-insights:alerts:staleness';

    /**
     * How many expected runs may be missed before a URL counts as stale, when
     * config carries no usable value.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_MISSED_CYCLES = 2;

    /**
     * Seconds before a URL that is still stale alerts again, when config
     * carries no usable value.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_REPEAT_AFTER = 86400;

    /**
     * Build the detector.
     *
     * @since 1.0.0
     *
     * @param  AlertDispatcher  $dispatcher  Where the alert is delivered from.
     * @param  ApiKeyRepository  $apiKeys  The configured API key storage driver.
     * @param  CacheFactory  $cache  The cache manager, for re-alert suppression.
     * @param  ConfigRepository  $config  The application config repository.
     * @param  LoggerInterface  $logger  Where detection diagnostics go.
     */
    public function __construct(
        protected AlertDispatcher $dispatcher,
        protected ApiKeyRepository $apiKeys,
        protected CacheFactory $cache,
        protected ConfigRepository $config,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Whether staleness alerting is switched on.
     *
     * Gated by the alerts master switch as well as its own, because an
     * installation that turned alerting off has said what it wants.
     *
     * @since 1.0.0
     *
     * @return bool True when stale URLs are reported.
     */
    public function enabled(): bool
    {
        return false !== $this->config->get( 'pagespeed-insights.alerts.enabled', true )
            && false !== $this->config->get( 'pagespeed-insights.alerts.staleness.enabled', true );
    }

    /**
     * Find the stale URLs, report them, and send one alert covering them all.
     *
     * @since 1.0.0
     *
     * @param  CarbonInterface|null  $now  The moment to measure staleness from; defaults to now.
     *
     * @return array<int, StaleUrl> The URLs alerted about, empty when none were.
     */
    public function handle( ?CarbonInterface $now = null ): array
    {
        if ( ! $this->enabled() ) {
            return [];
        }

        $stale = $this->detect( $now );

        if ( [] === $stale ) {
            return [];
        }

        $alerting = array_values( array_filter(
            $stale,
            fn ( StaleUrl $url ): bool => $this->shouldAlert( $url ),
        ) );

        if ( [] === $alerting ) {
            $this->logger->debug(
                'PageSpeed URLs are still not reporting, but each has alerted inside the alerts.staleness.repeat_after window, so no notification was sent.',
                [ 'urls' => count( $stale ) ],
            );

            return [];
        }

        $this->logger->warning(
            'PageSpeed URLs have stopped reporting.',
            [
                'urls' => array_map(
                    static fn ( StaleUrl $url ): array => $url->toArray(),
                    $alerting,
                ),
            ],
        );

        $this->fireWentStale( $alerting );

        $delivery = $this->dispatcher->notify( new StaleUrlNotification( $alerting, $this->dispatcher->channels() ) );

        if ( AlertDelivery::Failed === $delivery ) {
            // The window is a limit on repetition, not a licence to drop the
            // first one. A URL that stopped reporting into a five-minute SMTP
            // outage would otherwise stay quiet for a full repeat_after — the
            // one failure mode this detector exists to catch, lost to a race
            // with the mail server.
            $this->releaseAlerts( $alerting );

            $this->logger->warning(
                'A PageSpeed staleness alert could not be delivered, so these URLs will be alerted about again on the next pass.',
                [ 'urls' => count( $alerting ) ],
            );
        }

        return $alerting;
    }

    /**
     * Work out which monitored URLs have stopped reporting, without alerting.
     *
     * @since 1.0.0
     *
     * @param  CarbonInterface|null  $now  The moment to measure staleness from; defaults to now.
     *
     * @return array<int, StaleUrl> The stale URLs, empty when none are.
     */
    public function detect( ?CarbonInterface $now = null ): array
    {
        $now          = null === $now ? CarbonImmutable::now() : CarbonImmutable::instance( $now );
        $missedCycles = $this->missedCycles();
        $lastRuns     = $this->lastCompletedRuns();
        $stale        = [];

        // Both are facts about the installation rather than about any one
        // URL, so they are answered once for the pass. Asked per URL, the
        // database driver would run a query apiece, and a driver that cannot
        // read its storage would say so once per stale URL in the log.
        $globalCause = match ( true ) {
            ! $this->hasCredentials() => StaleUrl::CAUSE_NO_API_KEY,
            ! $this->schedulingOn()   => StaleUrl::CAUSE_SCHEDULING_DISABLED,
            default                   => null,
        };

        foreach ( PageSpeedUrl::query()->active()->orderBy( 'id' )->get() as $url ) {
            $address   = (string) $url->url;
            $frequency = $url->frequency();
            $lastRun   = $lastRuns[ $address ] ?? null;

            // Falls back to the monitored row's own stamp before falling back
            // to its creation date. `last_tested_at` is written only by a
            // completed run and is not prunable, so it still answers this
            // question on an install whose retention window is shorter than
            // the URL's cadence — where the results rows this lookup prefers
            // have already been swept, and their absence would otherwise read
            // as a URL that went quiet, diagnosed with a confident and wrong
            // "the runs are not reaching a worker".
            if ( null === $lastRun && null !== $url->last_tested_at ) {
                $lastRun = CarbonImmutable::instance( $url->last_tested_at );
            }

            // A URL that has never completed a run is measured from when it
            // was added: a page registered a minute ago has not "stopped"
            // reporting, it has not started. A row with no timestamp at all
            // — only reachable by a hand-written insert — has no window to
            // measure, and is left alone rather than alerted about forever.
            $since = $lastRun ?? ( null === $url->created_at ? null : CarbonImmutable::instance( $url->created_at ) );

            if ( null === $since ) {
                continue;
            }

            $window = PageSpeedUrl::FREQUENCY_INTERVALS[ $frequency ] * $missedCycles;

            if ( $since->greaterThan( $now->subMinutes( $window ) ) ) {
                continue;
            }

            $stale[] = $this->diagnose( $url, $frequency, $missedCycles, $lastRun, $since, $globalCause );
        }

        return $stale;
    }

    /**
     * Work out why one URL is probably not reporting.
     *
     * The order matters: a missing key or a switched-off scheduler explains
     * every stale URL at once and is the thing to fix, so it is named ahead of
     * the per-URL failures it would have caused anyway.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedUrl  $url  The monitored row.
     * @param  string  $frequency  Its resolved cadence.
     * @param  int  $missedCycles  The configured tolerance.
     * @param  CarbonImmutable|null  $lastRun  When it last completed a run.
     * @param  CarbonImmutable  $since  The moment the window was measured from.
     * @param  string|null  $globalCause  The installation-wide cause, when there is one.
     *
     * @return StaleUrl The diagnosis.
     */
    protected function diagnose(
        PageSpeedUrl $url,
        string $frequency,
        int $missedCycles,
        ?CarbonImmutable $lastRun,
        CarbonImmutable $since,
        ?string $globalCause = null,
    ): StaleUrl {
        // Counted and read as two queries rather than by hydrating the rows:
        // the alert wants how many failures there were and what the last one
        // said, and a URL failing hourly for a month has 700 rows to say it
        // with. A count is also the honest number — a capped `get()` would
        // report "50 failed runs" for a URL that has written five hundred.
        $failures = PageSpeedResult::query()
            ->forUrl( (string) $url->url )
            ->failed()
            ->where( 'created_at', '>=', $since )
            ->count();

        $latestError = $failures > 0 ? $this->latestFailureMessage( (string) $url->url, $since ) : null;

        $cause = match ( true ) {
            null !== $globalCause => $globalCause,
            $failures > 0         => StaleUrl::CAUSE_FAILING,
            default               => StaleUrl::CAUSE_NOT_RUNNING,
        };

        $label = null === $url->label || '' === trim( (string) $url->label ) ? null : trim( (string) $url->label );

        return new StaleUrl(
            url: (string) $url->url,
            frequency: $frequency,
            missedCycles: $missedCycles,
            cause: $cause,
            lastResultAt: $lastRun,
            causeDetail: $latestError,
            urlId: $url->getKey(),
            label: $label,
            failures: $failures,
        );
    }

    /**
     * What the most recent failure since the given moment said.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The monitored address.
     * @param  CarbonImmutable  $since  The moment to look forward from.
     *
     * @return string|null The message, or null when no failure recorded one.
     */
    protected function latestFailureMessage( string $url, CarbonImmutable $since ): ?string
    {
        $latest = PageSpeedResult::query()
            ->forUrl( $url )
            ->failed()
            ->where( 'created_at', '>=', $since )
            ->whereNotNull( 'error_message' )
            ->orderByDesc( 'created_at' )
            ->orderByDesc( 'id' )
            ->first();

        $message = null === $latest ? '' : trim( (string) $latest->error_message );

        return '' === $message ? null : $message;
    }

    /**
     * When each monitored address last completed a run.
     *
     * One aggregate rather than a query per URL: a monitored set of a few
     * hundred pages is checked on every scheduled pass, and the answer is a
     * single grouped max.
     *
     * @since 1.0.0
     *
     * @return array<string, CarbonImmutable> The timestamps, keyed by URL.
     */
    protected function lastCompletedRuns(): array
    {
        $rows = PageSpeedResult::query()
            ->completed()
            ->groupBy( 'url' )
            ->selectRaw( 'url, MAX(created_at) as last_completed_at' )
            ->get();

        $timestamps = [];

        foreach ( $rows as $row ) {
            $last = $row->getAttribute( 'last_completed_at' );

            if ( null === $last || '' === (string) $last ) {
                continue;
            }

            $timestamps[ (string) $row->url ] = CarbonImmutable::parse( (string) $last );
        }

        return $timestamps;
    }

    /**
     * Whether this URL may alert now, marking it as alerted about when it may.
     *
     * A URL that stays broken over a weekend would otherwise send an email on
     * every scheduled pass, which is how an alert becomes something people
     * filter into a folder.
     *
     * @since 1.0.0
     *
     * @param  StaleUrl  $url  The stale URL.
     *
     * @return bool True when it has not alerted inside the repeat window.
     */
    protected function shouldAlert( StaleUrl $url ): bool
    {
        $repeatAfter = $this->repeatAfter();

        if ( $repeatAfter <= 0 ) {
            return true;
        }

        // `add()` is the whole mechanism: it writes the marker only when
        // there is not one already, so two passes racing each other still
        // produce one alert.
        return $this->store()->add(
            $this->suppressionKey( $url ),
            true,
            $repeatAfter,
        );
    }

    /**
     * Give back the markers claimed for an alert that never went out.
     *
     * Only ever called for a delivery that threw. A notification nobody is
     * configured to receive is a permanent state and the documented way to run
     * this package on hooks and logs alone, so releasing there would re-fire
     * {@see self::ACTION_URL_WENT_STALE} on every scheduled pass for as long as
     * the URL stayed broken — the spam this window exists to prevent, arriving
     * from the other direction.
     *
     * @since 1.0.0
     *
     * @param  array<int, StaleUrl>  $urls  The URLs alerted about.
     *
     * @return void
     */
    protected function releaseAlerts( array $urls ): void
    {
        if ( $this->repeatAfter() <= 0 ) {
            return;
        }

        $store = $this->store();

        foreach ( $urls as $url ) {
            $store->forget( $this->suppressionKey( $url ) );
        }
    }

    /**
     * The suppression marker key for one stale URL.
     *
     * @since 1.0.0
     *
     * @param  StaleUrl  $url  The stale URL.
     *
     * @return string The cache key.
     */
    protected function suppressionKey( StaleUrl $url ): string
    {
        return self::SUPPRESSION_KEY . ':' . ( $url->urlId ?? md5( $url->url ) );
    }

    /**
     * Whether a key is available, treated as available when the driver cannot
     * be asked.
     *
     * A driver that throws is a diagnosis problem, not a reason to tell every
     * operator their key is missing.
     *
     * @since 1.0.0
     *
     * @return bool True when a key is configured.
     */
    protected function hasCredentials(): bool
    {
        try {
            return $this->apiKeys->isConfigured();
        } catch ( Throwable $exception ) {
            $this->logger->error(
                'The PageSpeed API key driver could not read its storage while diagnosing a stale URL, so the alert cannot say whether a key is configured.',
                [ 'driver' => $this->apiKeys::class, 'error' => $exception->getMessage() ],
            );

            return true;
        }
    }

    /**
     * Whether the package's own scheduling is switched on.
     *
     * @since 1.0.0
     *
     * @return bool True when scheduling is on.
     */
    protected function schedulingOn(): bool
    {
        return false !== $this->config->get( 'pagespeed-insights.scheduling.enabled', true );
    }

    /**
     * How many expected runs may be missed before a URL counts as stale.
     *
     * @since 1.0.0
     *
     * @return int The tolerance, never below one.
     */
    protected function missedCycles(): int
    {
        $configured = $this->config->get(
            'pagespeed-insights.alerts.staleness.missed_cycles',
            self::DEFAULT_MISSED_CYCLES,
        );

        if ( ! is_numeric( $configured ) || (int) $configured < 1 ) {
            // Zero would call a URL stale the instant its cadence elapsed,
            // which is one late queue worker away from alerting on a healthy
            // site, so it reads as a typo rather than an instruction.
            return self::DEFAULT_MISSED_CYCLES;
        }

        return (int) $configured;
    }

    /**
     * Seconds before a URL that is still stale alerts again.
     *
     * @since 1.0.0
     *
     * @return int The window, or 0 when suppression is off.
     */
    protected function repeatAfter(): int
    {
        $configured = $this->config->get(
            'pagespeed-insights.alerts.staleness.repeat_after',
            self::DEFAULT_REPEAT_AFTER,
        );

        if ( null === $configured || '' === $configured ) {
            return 0;
        }

        if ( ! is_numeric( $configured ) || (int) $configured < 0 ) {
            return self::DEFAULT_REPEAT_AFTER;
        }

        return (int) $configured;
    }

    /**
     * The cache store the suppression markers live in.
     *
     * @since 1.0.0
     *
     * @return CacheRepository The store.
     */
    protected function store(): CacheRepository
    {
        $name = $this->config->get( 'pagespeed-insights.alerts.staleness.store' );

        if ( ! is_string( $name ) || '' === trim( $name ) ) {
            $name = $this->config->get( 'pagespeed-insights.alerts.digest.store' );
        }

        return $this->cache->store( is_string( $name ) && '' !== trim( $name ) ? trim( $name ) : null );
    }

    /**
     * Fire the staleness action, if the hooks package is installed.
     *
     * @since 1.0.0
     *
     * @param  array<int, StaleUrl>  $stale  The URLs that stopped reporting.
     *
     * @return void
     */
    protected function fireWentStale( array $stale ): void
    {
        if ( ! function_exists( 'doAction' ) ) {
            return;
        }

        $ids = array_values( array_filter( array_map(
            static fn ( StaleUrl $url ): ?int => $url->urlId,
            $stale,
        ) ) );

        $models = [] === $ids
            ? collect()
            : PageSpeedUrl::query()->whereIn( 'id', $ids )->get()->keyBy( 'id' );

        foreach ( $stale as $url ) {
            doAction(
                self::ACTION_URL_WENT_STALE,
                null === $url->urlId ? null : $models->get( $url->urlId ),
                $url->toArray(),
            );
        }
    }
}
