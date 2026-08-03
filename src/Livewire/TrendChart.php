<?php

/**
 * Historical trend chart Livewire component.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Livewire;

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Data\LabMetrics;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Support\CategoryTranslator;
use ArtisanPackUI\PageSpeedInsights\Support\TrendSeries;
use ArtisanPackUI\PageSpeedInsights\Support\UiComponentsInstalled;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One measurement plotted over time for one URL, mobile against desktop.
 *
 * This is the component the results table exists for. Every other card in this
 * package reads the newest row and could be rebuilt from a single live API
 * call; this one is the only thing a year of stored history buys.
 *
 * ### A gap is drawn as a gap
 *
 * The temptation in a trend chart is to plot only the points that exist and
 * join them up, which turns a fortnight when testing silently stopped into a
 * confident straight line across it. Two things prevent that here:
 *
 * - **Failed runs are excluded, never zeroed.** A run that produced no
 *   measurement is not a score of zero, and averaging one in would render an
 *   outage as a catastrophic regression.
 * - **A completed run that lost this particular measurement plots as null.**
 *   ApexCharts breaks the line at a null, so a degraded run leaves a visible
 *   hole rather than being quietly skipped over — which is the same straight
 *   line by a different route.
 *
 * ### Two points is the floor
 *
 * A chart drawn from one measurement is not a trend, it is a dot, and it
 * invites a reader to conclude something about a direction that has not been
 * measured yet. Below two usable points the component says what it has instead
 * of drawing it, and it distinguishes a URL with no history at all from one
 * whose history simply falls outside the selected range — the second is fixed
 * by widening the range, the first by running a test, and offering the wrong
 * remedy wastes somebody's afternoon.
 *
 * ### What a viewer may change
 *
 * `$url` is `#[Locked]`: it decides whose history is read. `$metric` and
 * `$range` are not, because they are the component's own controls — but both
 * are reduced to a known value on every update, so a payload naming an
 * arbitrary column or an unbounded range cannot reach the query.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class TrendChart extends Component
{
    /**
     * No completed run has ever been stored for this URL.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_EMPTY = 'empty';

    /**
     * There is history, but none of it falls inside the selected range.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_OUT_OF_RANGE = 'out-of-range';

    /**
     * Fewer than two usable measurements, which is a dot rather than a trend.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_INSUFFICIENT = 'insufficient';

    /**
     * There is a trend to draw.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_LOADED = 'loaded';

    /**
     * The fewest usable points that make a trend rather than a dot.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MINIMUM_POINTS = TrendSeries::MINIMUM_POINTS;

    /**
     * The most runs one chart will plot.
     *
     * An hourly cadence across both form factors writes about 17,500 rows a
     * year, and every one of them would otherwise be hydrated, serialized into
     * the Livewire payload, and handed to ApexCharts on every metric change.
     * Five hundred points is already more than a chart this size can resolve
     * — and when the cap bites the component says so, because a silently
     * shortened history is exactly the kind of quiet misreading this chart
     * exists to prevent.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_RESULTS = TrendSeries::MAX_RESULTS;

    /**
     * The columns a plotted point is built from.
     *
     * Named explicitly so that the json columns this chart never reads —
     * `raw_response` above all — stay out of a query that may return hundreds
     * of rows.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const PLOTTED_COLUMNS = TrendSeries::PLOTTED_COLUMNS;

    /**
     * The ranges the selector offers, in days.
     *
     * Capped at a year because that is where `retention.days` defaults, so a
     * wider window would offer a view the data has already been pruned out of.
     *
     * @since 1.0.0
     *
     * @var array<int, int>
     */
    public const RANGES = TrendSeries::RANGES;

    /**
     * The lab metrics that can be plotted, on top of the four categories.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const LAB_METRICS = LabMetrics::AUDIT_IDS;

    /**
     * The form factors plotted as separate series.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const STRATEGIES = TrendSeries::STRATEGIES;

    /**
     * The URL this trend describes.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Locked]
    public string $url = '';

    /**
     * The category or lab metric being plotted.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $metric = 'performance';

    /**
     * How many days back the chart reaches.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $range = 90;

    /**
     * Which of the four states this chart is in.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $state = self::STATE_EMPTY;

    /**
     * Whether the component library the view renders with is installed.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $uiComponentsInstalled = true;

    /**
     * The plotted series, one per form factor that has data.
     *
     * @since 1.0.0
     *
     * @var array<int, array{name: string, data: array<int, array{x: string, y: float|int|null}>}>
     */
    #[Locked]
    public array $series = [];

    /**
     * How many usable measurements the chart is drawn from.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $pointCount = 0;

    /**
     * Whether this URL has history the selected range excludes.
     *
     * Carried so the empty states can offer the right remedy: widening the
     * range and running a test are different answers to what looks like the
     * same blank chart.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $hasOlderHistory = false;

    /**
     * Whether any plotted run lost the measurement being charted.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $hasGaps = false;

    /**
     * Whether the range holds more runs than {@see self::MAX_RESULTS}.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $truncated = false;

    /**
     * Set the chart up for one URL.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL to chart.
     * @param  string|null  $metric  The category or lab metric to plot; null uses performance.
     * @param  int|string|null  $range  How many days back to reach; null uses 90.
     *
     * @return void
     */
    public function mount( string $url = '', ?string $metric = null, int|string|null $range = null ): void
    {
        $this->url    = UrlNormalizer::normalize( $url ) ?? trim( $url );
        $this->metric = self::normalizeMetric( $metric );
        $this->range  = self::normalizeRange( $range );

        $this->refresh();
    }

    /**
     * Rebuild the chart after the metric selector changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedMetric(): void
    {
        $this->metric = self::normalizeMetric( $this->metric );

        $this->refresh();
    }

    /**
     * Rebuild the chart after the range selector changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedRange(): void
    {
        $this->range = self::normalizeRange( $this->range );

        $this->refresh();
    }

    /**
     * Rebuild the series from stored history.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->uiComponentsInstalled = UiComponentsInstalled::check();

        $this->series          = [];
        $this->pointCount      = 0;
        $this->hasGaps         = false;
        $this->hasOlderHistory = false;
        $this->truncated       = false;

        if ( '' === $this->url ) {
            $this->state = self::STATE_EMPTY;

            return;
        }

        $results = $this->resultsInRange();

        if ( $results->isEmpty() ) {
            $this->hasOlderHistory = $this->hasHistoryOutsideRange();
            $this->state           = $this->hasOlderHistory ? self::STATE_OUT_OF_RANGE : self::STATE_EMPTY;

            return;
        }

        [ $series, $points, $gaps ] = $this->buildSeries( $results );

        $this->series     = $series;
        $this->pointCount = $points;
        $this->hasGaps    = $gaps;

        if ( $points < self::MINIMUM_POINTS ) {
            // A single measurement is not a direction. Say so rather than
            // drawing a chart a reader would read a trend into.
            $this->hasOlderHistory = $this->hasHistoryOutsideRange();
            $this->series          = [];
            $this->state           = self::STATE_INSUFFICIENT;

            return;
        }

        $this->state = self::STATE_LOADED;
    }

    /**
     * Pick up a run the score card was waiting on.
     *
     * Matched on the URL only. A run on either form factor adds a point to
     * this chart, since both are plotted.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL the finished run was for.
     *
     * @return void
     */
    #[On( ScoreCard::EVENT_RESULT_STORED )]
    public function onResultStored( string $url = '' ): void
    {
        if ( $url !== $this->url ) {
            return;
        }

        $this->refresh();
    }

    /**
     * The metrics the selector offers, as value and label pairs.
     *
     * @since 1.0.0
     *
     * @return array<int, array{value: string, label: string}> The options, in display order.
     */
    public function metricOptions(): array
    {
        $options = [];

        foreach ( array_merge( CategoryTranslator::KNOWN, self::LAB_METRICS ) as $metric ) {
            $options[] = [
                'value' => $metric,
                'label' => self::metricLabel( $metric ),
            ];
        }

        return $options;
    }

    /**
     * The ranges the selector offers, as value and label pairs.
     *
     * @since 1.0.0
     *
     * @return array<int, array{value: int, label: string}> The options, in display order.
     */
    public function rangeOptions(): array
    {
        $options = [];

        foreach ( self::RANGES as $days ) {
            $options[] = [
                'value' => $days,
                'label' => trans_choice( 'Last :count day|Last :count days', $days, [ 'count' => $days ] ),
            ];
        }

        return $options;
    }

    /**
     * The metric being plotted, written for a human.
     *
     * @since 1.0.0
     *
     * @return string The translated label.
     */
    public function metricTitle(): string
    {
        return self::metricLabel( $this->metric );
    }

    /**
     * The ApexCharts options for the current metric.
     *
     * Category scores are pinned to a 0-100 axis so that a page holding steady
     * in the nineties does not render as a jagged mountain range, which is
     * what an auto-scaled axis makes of three points of noise.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The chart options.
     */
    public function chartOptions(): array
    {
        $isCategory = CategoryTranslator::isKnown( $this->metric );

        return [
            'chart'   => [
                'type'    => 'line',
                'toolbar' => [ 'show' => false ],
            ],
            'stroke'  => [ 'curve' => 'straight', 'width' => 2 ],
            'markers' => [ 'size' => 3 ],
            'xaxis'   => [
                'type'  => 'datetime',
                'title' => [ 'text' => __( 'Tested' ) ],
            ],
            'yaxis'   => [
                'min'   => 0,
                'max'   => $isCategory ? 100 : null,
                'title' => [ 'text' => $this->axisTitle() ],
            ],
            'tooltip' => [ 'x' => [ 'format' => 'dd MMM yyyy HH:mm' ] ],
        ];
    }

    /**
     * Render the chart.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        return view( 'pagespeed-insights::livewire.trend-chart' );
    }

    /**
     * The completed runs for this URL inside the selected range, oldest first.
     *
     * Filtered and ordered on `created_at` rather than on `fetched_at`,
     * because `fetched_at` is Google's own analysis timestamp and a row may
     * carry none. The id breaks a same-second tie so that the two form
     * factors of one scheduled cycle land in a stable order.
     *
     * Only the columns a point is built from are selected. The obvious
     * `->get()` also loads `raw_response`, `field_data`, and `origin_field_data`
     * — hundreds of kilobytes per row on an install that retains payloads —
     * for a chart that reads none of them, which turns a year of history into
     * a query that can exhaust the request's memory limit outright.
     *
     * The newest {@see self::MAX_RESULTS} are taken and then reversed, rather
     * than the oldest, because a truncated chart must end at today: a line
     * that stops silently three months ago reads as testing having stopped.
     *
     * @since 1.0.0
     *
     * @return Collection<int, PageSpeedResult> The runs to plot, oldest first.
     */
    protected function resultsInRange(): Collection
    {
        $results = PageSpeedResult::query()
            ->forUrl( $this->url )
            ->completed()
            ->where( 'created_at', '>=', $this->rangeStart() )
            ->select( self::PLOTTED_COLUMNS )
            ->orderByDesc( 'created_at' )
            ->orderByDesc( 'id' )
            // One more than the cap, so that a full page proves there is more
            // behind it without a second counting query.
            ->limit( self::MAX_RESULTS + 1 )
            ->get();

        $this->truncated = $results->count() > self::MAX_RESULTS;

        return $results->take( self::MAX_RESULTS )->reverse()->values();
    }

    /**
     * Whether this URL has completed runs the selected range leaves out.
     *
     * @since 1.0.0
     *
     * @return bool True when widening the range would show more.
     */
    protected function hasHistoryOutsideRange(): bool
    {
        return PageSpeedResult::query()
            ->forUrl( $this->url )
            ->completed()
            ->where( 'created_at', '<', $this->rangeStart() )
            ->exists();
    }

    /**
     * The oldest moment the selected range reaches back to.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable The cutoff.
     */
    protected function rangeStart(): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays( $this->range );
    }

    /**
     * Turn stored runs into one series per form factor.
     *
     * A form factor with no run at all in the range is left off entirely
     * rather than added as an empty series, so a URL only ever tested on
     * mobile does not carry a permanent, meaningless "Desktop" legend entry.
     *
     * @since 1.0.0
     *
     * @param  Collection<int, PageSpeedResult>  $results  The runs to plot, oldest first.
     *
     * @return array{0: array<int, array{name: string, data: array<int, array{x: string, y: float|int|null}>}>, 1: int, 2: bool} The series, the count of usable points, and whether any run lost the measurement.
     */
    protected function buildSeries( Collection $results ): array
    {
        $series = [];
        $points = 0;
        $gaps   = false;

        foreach ( self::STRATEGIES as $strategy ) {
            $rows = $results->where( 'strategy', $strategy );

            if ( $rows->isEmpty() ) {
                continue;
            }

            $data = [];

            foreach ( $rows as $result ) {
                $value = $this->valueFor( $result );

                if ( null === $value ) {
                    // Plotted as a null rather than skipped: ApexCharts breaks
                    // the line at one, so a run that lost this measurement
                    // shows as a hole instead of being joined straight over.
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
                'name' => self::strategyLabel( $strategy ),
                'data' => $data,
            ];
        }

        return [ $series, $points, $gaps ];
    }

    /**
     * The measurement being charted, read off one run.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The run to read.
     *
     * @return float|int|null The measurement, or null when this run lost it.
     */
    protected function valueFor( PageSpeedResult $result ): float|int|null
    {
        if ( CategoryTranslator::isKnown( $this->metric ) ) {
            return $result->scores()->get( $this->metric );
        }

        $stored = $result->lab_metrics[ $this->metric ][ 'value' ] ?? null;

        return is_numeric( $stored ) ? (float) $stored : null;
    }

    /**
     * The unit the y-axis is measured in, written for a human.
     *
     * @since 1.0.0
     *
     * @return string The translated axis title.
     */
    protected function axisTitle(): string
    {
        if ( CategoryTranslator::isKnown( $this->metric ) ) {
            return __( 'Score' );
        }

        return 'cumulative-layout-shift' === $this->metric
            ? __( 'Layout shift' )
            : __( 'Milliseconds' );
    }

    /**
     * A category or lab metric's name, written for a human.
     *
     * @since 1.0.0
     *
     * @param  string  $metric  The category or audit id.
     *
     * @return string The translated label.
     */
    protected static function metricLabel( string $metric ): string
    {
        return match ( $metric ) {
            'performance'              => __( 'Performance' ),
            'accessibility'            => __( 'Accessibility' ),
            'best-practices'           => __( 'Best practices' ),
            'seo'                      => __( 'SEO' ),
            'first-contentful-paint'   => __( 'First Contentful Paint' ),
            'largest-contentful-paint' => __( 'Largest Contentful Paint' ),
            'total-blocking-time'      => __( 'Total Blocking Time' ),
            'cumulative-layout-shift'  => __( 'Cumulative Layout Shift' ),
            'speed-index'              => __( 'Speed Index' ),
            default                    => $metric,
        };
    }

    /**
     * A form factor's name, written for a human.
     *
     * @since 1.0.0
     *
     * @param  string  $strategy  mobile or desktop.
     *
     * @return string The translated label.
     */
    protected static function strategyLabel( string $strategy ): string
    {
        return PageSpeedRequest::STRATEGY_DESKTOP === $strategy
            ? __( 'Desktop' )
            : __( 'Mobile' );
    }

    /**
     * Reduce a requested metric to one this chart can plot.
     *
     * @since 1.0.0
     *
     * @param  string|null  $metric  The requested metric.
     *
     * @return string A known category or lab metric audit id.
     */
    protected static function normalizeMetric( ?string $metric ): string
    {
        $normalized = strtolower( trim( (string) $metric ) );
        $known      = array_merge( CategoryTranslator::KNOWN, self::LAB_METRICS );

        return in_array( $normalized, $known, true ) ? $normalized : 'performance';
    }

    /**
     * Reduce a requested range to one the selector offers.
     *
     * Anything unrecognised falls back to the default rather than being
     * clamped to the nearest offered value: a payload naming 100,000 days is
     * not a request for a year, it is a request the component does not honour.
     *
     * @since 1.0.0
     *
     * @param  int|string|null  $range  The requested range in days.
     *
     * @return int A range from {@see self::RANGES}.
     */
    protected static function normalizeRange( int|string|null $range ): int
    {
        $normalized = is_numeric( $range ) ? (int) $range : 0;

        return in_array( $normalized, self::RANGES, true ) ? $normalized : 90;
    }
}
