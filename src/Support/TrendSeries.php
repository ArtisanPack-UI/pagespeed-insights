<?php

/**
 * Builds a measurement's history into plottable series.
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

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Data\LabMetrics;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One measurement over time, for one URL, as data.
 *
 * The bounds a trend is drawn inside — how far back a range may reach, how many
 * points may be plotted, how few of them stop being a trend — are decisions
 * about the history table rather than about any one surface, so they live here
 * and both {@see \ArtisanPackUI\PageSpeedInsights\Livewire\TrendChart} and the
 * HTTP endpoint read them from the same place. Two copies of `MAX_RESULTS` is
 * two answers to "why does my chart stop three months ago".
 *
 * ### A gap is drawn as a gap
 *
 * Failed runs are excluded rather than zeroed: a run that produced no
 * measurement is not a score of zero, and averaging one in renders an outage as
 * a catastrophic regression. A *completed* run that lost this particular
 * measurement is emitted as a null point rather than skipped, so a client
 * breaks its line at the hole instead of joining a confident straight line
 * across a fortnight when testing silently stopped.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class TrendSeries
{
    /**
     * The ranges a trend may be drawn over, in days.
     *
     * Capped at a year because that is where `retention.days` defaults, so a
     * wider window would offer a view the data has already been pruned out of.
     *
     * @since 1.0.0
     *
     * @var array<int, int>
     */
    public const RANGES = [ 7, 30, 90, 365 ];

    /**
     * The range used when none is asked for, or an unknown one is.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_RANGE = 90;

    /**
     * The measurement plotted when none is asked for, or an unknown one is.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DEFAULT_METRIC = 'performance';

    /**
     * The most runs one trend will carry.
     *
     * An hourly cadence across both form factors writes about 17,500 rows a
     * year, and every one of them would otherwise be hydrated and serialized.
     * Five hundred points is already more than a chart can resolve — and when
     * the cap bites it is reported, because a silently shortened history is
     * exactly the kind of quiet misreading a trend exists to prevent.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_RESULTS = 500;

    /**
     * The fewest usable points that make a trend rather than a dot.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MINIMUM_POINTS = 2;

    /**
     * The columns a plotted point is built from.
     *
     * Named explicitly so that the json columns a trend never reads —
     * `raw_response` above all — stay out of a query that may return hundreds
     * of rows.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const PLOTTED_COLUMNS = [
        'id',
        'url',
        'strategy',
        'status',
        'performance_score',
        'accessibility_score',
        'best_practices_score',
        'seo_score',
        'lab_metrics',
        'warnings',
        'fetched_at',
        'created_at',
    ];

    /**
     * The form factors plotted as separate series.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const STRATEGIES = [
        PageSpeedRequest::STRATEGY_MOBILE,
        PageSpeedRequest::STRATEGY_DESKTOP,
    ];

    /**
     * Every measurement a trend can be drawn for.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The four categories, then the lab metrics.
     */
    public static function metrics(): array
    {
        return array_merge( CategoryTranslator::KNOWN, LabMetrics::AUDIT_IDS );
    }

    /**
     * Reduce a requested metric to one a trend can be drawn for.
     *
     * @since 1.0.0
     *
     * @param  mixed  $metric  The requested metric.
     *
     * @return string A known category or lab metric audit id.
     */
    public static function normalizeMetric( mixed $metric ): string
    {
        $normalized = is_scalar( $metric ) ? strtolower( trim( (string) $metric ) ) : '';

        return in_array( $normalized, self::metrics(), true ) ? $normalized : self::DEFAULT_METRIC;
    }

    /**
     * Reduce a requested range to one a trend may be drawn over.
     *
     * Anything unrecognised falls back to the default rather than being
     * clamped to the nearest offered value: a payload naming 100,000 days is
     * not a request for a year, it is a request the package does not honour.
     *
     * @since 1.0.0
     *
     * @param  mixed  $range  The requested range in days.
     *
     * @return int A range from {@see self::RANGES}.
     */
    public static function normalizeRange( mixed $range ): int
    {
        $normalized = is_numeric( $range ) ? (int) $range : 0;

        return in_array( $normalized, self::RANGES, true ) ? $normalized : self::DEFAULT_RANGE;
    }

    /**
     * Build one URL's trend.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The canonical URL.
     * @param  string  $metric  A value of {@see self::metrics()}.
     * @param  int  $range  A value of {@see self::RANGES}.
     * @param  string|null  $strategy  One form factor, or null for every one that has data.
     *
     * @return array{series: array<int, array{strategy: string, points: array<int, array{x: string, y: float|int|null}>}>, pointCount: int, hasGaps: bool, truncated: bool, hasOlderHistory: bool} The trend.
     */
    public function build( string $url, string $metric, int $range, ?string $strategy = null ): array
    {
        $rangeStart = CarbonImmutable::now()->subDays( $range );
        $strategies = null === $strategy ? self::STRATEGIES : [ $strategy ];

        $query = PageSpeedResult::query()
            ->forUrl( $url )
            ->completed()
            ->whereIn( 'strategy', $strategies )
            ->where( 'created_at', '>=', $rangeStart )
            ->select( self::PLOTTED_COLUMNS )
            ->orderByDesc( 'created_at' )
            ->orderByDesc( 'id' )
            // One more than the cap, so that a full page proves there is more
            // behind it without a second counting query.
            ->limit( self::MAX_RESULTS + 1 );

        $rows = $query->get();

        $truncated = $rows->count() > self::MAX_RESULTS;

        // The newest MAX_RESULTS are taken and then reversed, rather than the
        // oldest, because a truncated trend must end at today: a line that
        // stops silently three months ago reads as testing having stopped.
        $results = $rows->take( self::MAX_RESULTS )->reverse()->values();

        [ $series, $points, $gaps ] = $this->buildSeries( $results, $metric, $strategies );

        return [
            'series'          => $series,
            'pointCount'      => $points,
            'hasGaps'         => $gaps,
            'truncated'       => $truncated,
            'hasOlderHistory' => $this->hasHistoryBefore( $url, $strategies, $rangeStart ),
        ];
    }

    /**
     * Whether a URL has completed runs older than the selected range.
     *
     * Carried so a client can offer the right remedy: widening the range and
     * running a test are different answers to what looks like the same blank
     * chart.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The canonical URL.
     * @param  array<int, string>  $strategies  The form factors in play.
     * @param  CarbonImmutable  $rangeStart  The oldest moment the range reaches.
     *
     * @return bool True when widening the range would show more.
     */
    protected function hasHistoryBefore( string $url, array $strategies, CarbonImmutable $rangeStart ): bool
    {
        return PageSpeedResult::query()
            ->forUrl( $url )
            ->completed()
            ->whereIn( 'strategy', $strategies )
            ->where( 'created_at', '<', $rangeStart )
            ->exists();
    }

    /**
     * Turn stored runs into one series per form factor.
     *
     * A form factor with no run at all in the range is left off entirely
     * rather than added as an empty series, so a URL only ever tested on
     * mobile does not carry a permanent, meaningless desktop series.
     *
     * @since 1.0.0
     *
     * @param  Collection<int, PageSpeedResult>  $results  The runs to plot, oldest first.
     * @param  string  $metric  The measurement being plotted.
     * @param  array<int, string>  $strategies  The form factors in play.
     *
     * @return array{0: array<int, array{strategy: string, points: array<int, array{x: string, y: float|int|null}>}>, 1: int, 2: bool} The series, the count of usable points, and whether any run lost the measurement.
     */
    protected function buildSeries( Collection $results, string $metric, array $strategies ): array
    {
        $series = [];
        $points = 0;
        $gaps   = false;

        foreach ( $strategies as $strategy ) {
            $rows = $results->where( 'strategy', $strategy );

            if ( $rows->isEmpty() ) {
                continue;
            }

            $data = [];

            foreach ( $rows as $result ) {
                $value = self::valueFor( $result, $metric );

                if ( null === $value ) {
                    $gaps = true;
                } else {
                    ++$points;
                }

                $data[] = [
                    'x' => ( $result->fetched_at ?? $result->created_at )?->toIso8601String() ?? '',
                    'y' => $value,
                ];
            }

            $series[] = [
                'strategy' => $strategy,
                'points'   => $data,
            ];
        }

        return [ $series, $points, $gaps ];
    }

    /**
     * The measurement being plotted, read off one run.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The run to read.
     * @param  string  $metric  The measurement being plotted.
     *
     * @return float|int|null The measurement, or null when this run lost it.
     */
    protected static function valueFor( PageSpeedResult $result, string $metric ): float|int|null
    {
        if ( CategoryTranslator::isKnown( $metric ) ) {
            return $result->scores()->get( $metric );
        }

        $stored = $result->lab_metrics[ $metric ][ 'value' ] ?? null;

        return is_numeric( $stored ) ? (float) $stored : null;
    }
}
