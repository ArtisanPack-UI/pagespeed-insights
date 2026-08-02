<?php

/**
 * Score regression detection.
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

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * Compares a completed run with the one before it and reports what got worse.
 *
 * Runs after each result is stored. Two things make a category alertable:
 * an absolute floor from `alerts.thresholds`, and a fall of at least
 * `alerts.drop_points` against the previous run for the same URL and form
 * factor. A third — a category that was scored last run and is null now — is
 * reported as its own kind of news rather than skipped, because "nothing to
 * compare" is precisely how a page that stopped being measured stays quiet.
 *
 * ### What it compares against
 *
 * The most recent *completed* run for the same URL and strategy, older than
 * the one being inspected, skipping runs that completed while losing data
 * when `alerts.skip_degraded` is on. Failed runs never qualify: they carry no
 * scores, so comparing against one would read every recovery as a hundred-
 * point gain and every fresh failure as nothing at all.
 *
 * A URL with no such run yet is a no-op for the comparative checks, logged at
 * debug level so "why did I not get an alert" is answerable from the log
 * rather than from the source. Floors still apply — a floor is a statement
 * about the score itself, not about the trend.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class RegressionDetector
{
    /**
     * Action fired when a stored run is found to have regressed.
     *
     * Callbacks receive the {@see PageSpeedResult} and a list of regression
     * arrays. Fired per result at detection time rather than when the digest
     * goes out, so a listener sees the regression whether or not anybody is
     * configured to be emailed about it.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ACTION_SCORE_REGRESSED = 'ap.pageSpeed.scoreRegressed';

    /**
     * Points a category may lose before it counts as a regression, when
     * config carries no usable value.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_DROP_POINTS = 10;

    /**
     * Build the detector.
     *
     * @since 1.0.0
     *
     * @param  AlertDispatcher  $dispatcher  Where detected regressions are sent.
     * @param  ConfigRepository  $config  The application config repository.
     * @param  LoggerInterface  $logger  Where detection diagnostics go.
     */
    public function __construct(
        protected AlertDispatcher $dispatcher,
        protected ConfigRepository $config,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Whether alerting is switched on at all.
     *
     * @since 1.0.0
     *
     * @return bool True when the master switch is on.
     */
    public function enabled(): bool
    {
        return false !== $this->config->get( 'pagespeed-insights.alerts.enabled', true );
    }

    /**
     * Inspect a stored run, report what regressed, and queue the alert.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The stored run.
     *
     * @return array<int, Regression> What regressed, empty when nothing did.
     */
    public function handle( PageSpeedResult $result ): array
    {
        if ( ! $this->enabled() ) {
            return [];
        }

        $regressions = $this->inspect( $result );

        if ( [] === $regressions ) {
            return [];
        }

        $this->logger->warning(
            'PageSpeed scores regressed.',
            [
                'url'         => $result->url,
                'strategy'    => $result->strategy,
                'result_id'   => $result->getKey(),
                'regressions' => array_map(
                    static fn ( Regression $regression ): array => $regression->toArray(),
                    $regressions,
                ),
            ],
        );

        $this->fireRegressed( $result, $regressions );

        $this->dispatcher->report( $regressions );

        return $regressions;
    }

    /**
     * Work out what regressed on a stored run, without alerting on it.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The stored run.
     *
     * @return array<int, Regression> What regressed, empty when nothing did.
     */
    public function inspect( PageSpeedResult $result ): array
    {
        // A failed run carries no scores. It is not silence — `status =
        // failed` is a row somebody can see, and a URL that keeps writing
        // them is the staleness detector's business, not this one's.
        if ( ! $result->isCompleted() ) {
            return [];
        }

        $previous   = $this->previousResult( $result );
        $thresholds = $this->thresholds();
        $dropPoints = $this->dropPoints();
        $degraded   = $result->wasDegraded();
        $label      = $this->label( $result );

        if ( null === $previous ) {
            $this->logger->debug(
                'A PageSpeed run has no earlier completed run to be compared with, so no score drop could be detected for it. Configured score floors still apply.',
                [
                    'url'       => $result->url,
                    'strategy'  => $result->strategy,
                    'result_id' => $result->getKey(),
                ],
            );
        }

        $regressions = [];

        foreach ( PageSpeedResult::SCORE_COLUMNS as $category => $column ) {
            $current = $this->score( $result, $column );
            $before  = null === $previous ? null : $this->score( $previous, $column );

            $regression = $this->compare(
                current: $current,
                before: $before,
                threshold: $thresholds[ $category ] ?? null,
                dropPoints: $dropPoints,
            );

            if ( null === $regression ) {
                continue;
            }

            $regressions[] = new Regression(
                type: $regression[ 'type' ],
                url: (string) $result->url,
                strategy: (string) $result->strategy,
                category: $category,
                currentScore: $current,
                previousScore: $before,
                threshold: $regression[ 'threshold' ],
                resultId: $result->getKey(),
                previousResultId: $previous?->getKey(),
                degraded: $degraded,
                label: $label,
            );
        }

        return $regressions;
    }

    /**
     * Decide whether one category regressed, and on which count.
     *
     * A category can breach its floor and lose ten points in the same run.
     * That is one piece of news, not two, so one regression comes back — the
     * floor named first, because "below 50" is the more actionable half.
     *
     * @since 1.0.0
     *
     * @param  int|null  $current  This run's score.
     * @param  int|null  $before  The previous run's score, when there was one.
     * @param  int|null  $threshold  The configured floor, when there is one.
     * @param  int  $dropPoints  Points a score may lose before it counts; 0 turns drop detection off.
     *
     * @return array{type: string, threshold: int|null}|null The verdict, or null when nothing regressed.
     */
    protected function compare(
        ?int $current,
        ?int $before,
        ?int $threshold,
        int $dropPoints,
    ): ?array {
        if ( null === $current ) {
            // Absent on both runs is not a regression: an install that never
            // requested a category should not be told hourly that it is
            // missing.
            return null === $before ? null : [ 'type' => Regression::TYPE_STOPPED, 'threshold' => null ];
        }

        if ( null !== $threshold && $current < $threshold ) {
            return [ 'type' => Regression::TYPE_THRESHOLD, 'threshold' => $threshold ];
        }

        if ( $dropPoints > 0 && null !== $before && ( $before - $current ) >= $dropPoints ) {
            return [ 'type' => Regression::TYPE_DROP, 'threshold' => null ];
        }

        return null;
    }

    /**
     * The run this one is compared with.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The run being inspected.
     *
     * @return PageSpeedResult|null The earlier run, or null when there is none worth comparing with.
     */
    protected function previousResult( PageSpeedResult $result ): ?PageSpeedResult
    {
        $query = PageSpeedResult::query()
            ->latestFor( (string) $result->url, (string) $result->strategy )
            ->completed();

        if ( null !== $result->getKey() ) {
            $query->where( 'id', '<', $result->getKey() );
        }

        if ( false === $this->config->get( 'pagespeed-insights.alerts.skip_degraded', true ) ) {
            return $query->first();
        }

        // Degradation lives in a json column no portable query can filter on,
        // so the skip happens in PHP over a bounded window. Ten runs back is
        // far enough to step over a bad patch and short enough that a URL
        // whose every recent run was degraded reports "nothing to compare"
        // rather than reaching back a year for a baseline nobody would
        // recognise.
        foreach ( $query->limit( 10 )->get() as $candidate ) {
            if ( ! $candidate->wasDegraded() ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * One score off a stored run, as an integer or null.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The run to read.
     * @param  string  $column  The score column.
     *
     * @return int|null The score, or null when the run has none.
     */
    protected function score( PageSpeedResult $result, string $column ): ?int
    {
        $value = $result->{$column};

        return null === $value ? null : (int) $value;
    }

    /**
     * The monitored URL's label, when the run belongs to one that still
     * exists and has been given one.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The run to read.
     *
     * @return string|null The label.
     */
    protected function label( PageSpeedResult $result ): ?string
    {
        $label = $result->pageSpeedUrl?->label;

        return null === $label || '' === trim( (string) $label ) ? null : trim( (string) $label );
    }

    /**
     * The configured floor for each category.
     *
     * A floor outside 0-100 is dropped rather than clamped: 0 would alert on
     * nothing and 101 on everything, and both read as a typo rather than an
     * instruction.
     *
     * @since 1.0.0
     *
     * @return array<string, int> The usable floors, keyed by category.
     */
    protected function thresholds(): array
    {
        $configured = $this->config->get( 'pagespeed-insights.alerts.thresholds', [] );

        if ( ! is_array( $configured ) ) {
            return [];
        }

        $thresholds = [];

        foreach ( $configured as $category => $threshold ) {
            if ( ! is_numeric( $threshold ) ) {
                continue;
            }

            $value = (int) $threshold;

            if ( $value < 1 || $value > 100 ) {
                $this->logger->warning(
                    'A configured PageSpeed score floor is not a usable Lighthouse score between 1 and 100, so it was ignored. A floor of 0 would alert on nothing and one above 100 on everything, so both read as a typo rather than an instruction.',
                    [ 'category' => (string) $category, 'threshold' => $threshold ],
                );

                continue;
            }

            $thresholds[ (string) $category ] = $value;
        }

        return $thresholds;
    }

    /**
     * How many points a category may lose before it counts as a regression.
     *
     * @since 1.0.0
     *
     * @return int The configured margin, or 0 when drop detection is off.
     */
    protected function dropPoints(): int
    {
        $configured = $this->config->get( 'pagespeed-insights.alerts.drop_points', self::DEFAULT_DROP_POINTS );

        if ( null === $configured || '' === $configured ) {
            return 0;
        }

        if ( ! is_numeric( $configured ) || (int) $configured < 0 ) {
            return self::DEFAULT_DROP_POINTS;
        }

        return (int) $configured;
    }

    /**
     * Fire the regression action, if the hooks package is installed.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The stored run.
     * @param  array<int, Regression>  $regressions  What regressed.
     *
     * @return void
     */
    protected function fireRegressed( PageSpeedResult $result, array $regressions ): void
    {
        if ( ! function_exists( 'doAction' ) ) {
            return;
        }

        doAction(
            self::ACTION_SCORE_REGRESSED,
            $result,
            array_map( static fn ( Regression $regression ): array => $regression->toArray(), $regressions ),
        );
    }
}
