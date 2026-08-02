<?php

/**
 * Stored PageSpeed result model.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Models;

use ArtisanPackUI\PageSpeedInsights\Data\FieldData;
use ArtisanPackUI\PageSpeedInsights\Data\LabMetrics;
use ArtisanPackUI\PageSpeedInsights\Data\Opportunity;
use ArtisanPackUI\PageSpeedInsights\Data\ScoreSet;
use ArtisanPackUI\PageSpeedInsights\Data\TestResult;
use ArtisanPackUI\PageSpeedInsights\Database\Factories\PageSpeedResultFactory;
use ArtisanPackUI\PageSpeedInsights\Support\CategoryTranslator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * One PageSpeed run, kept.
 *
 * The four score columns mirror {@see CategoryTranslator::KNOWN}. A category
 * Lighthouse adds later has no column to land in, and a write must not fail
 * over that — Lighthouse revises its lineup roughly every two releases — so
 * {@see self::fromTestResult()} drops it, logs it by name, and records it in
 * `warnings`. That last part is the point: a row that quietly lost a score
 * has to be able to say so months later, when the trend chart looks wrong
 * and there is nothing else left to check.
 *
 * @property int $id
 * @property int|null $pagespeed_url_id
 * @property string $url
 * @property string|null $final_url
 * @property string $strategy
 * @property int|null $performance_score
 * @property int|null $accessibility_score
 * @property int|null $best_practices_score
 * @property int|null $seo_score
 * @property array<string, mixed>|null $lab_metrics
 * @property array<string, mixed>|null $field_data
 * @property array<string, mixed>|null $origin_field_data
 * @property array<int, mixed>|null $opportunities
 * @property array<string, mixed>|null $warnings
 * @property string|null $lighthouse_version
 * @property array<string, mixed>|null $raw_response
 * @property string $status
 * @property string|null $error_message
 * @property CarbonImmutable|null $fetched_at
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class PageSpeedResult extends Model
{
    use HasFactory;

    /**
     * The run finished and produced scores, however thin.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * The run did not produce a result; `error_message` says why.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_FAILED = 'failed';

    /**
     * The score column each stored Lighthouse category writes to.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const SCORE_COLUMNS = [
        'performance'    => 'performance_score',
        'accessibility'  => 'accessibility_score',
        'best-practices' => 'best_practices_score',
        'seo'            => 'seo_score',
    ];

    /**
     * The table associated with the model.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'pagespeed_results';

    /**
     * The attributes that are mass assignable.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'pagespeed_url_id',
        'url',
        'final_url',
        'strategy',
        'performance_score',
        'accessibility_score',
        'best_practices_score',
        'seo_score',
        'lab_metrics',
        'field_data',
        'origin_field_data',
        'opportunities',
        'warnings',
        'lighthouse_version',
        'raw_response',
        'status',
        'error_message',
        'fetched_at',
    ];

    /**
     * The model's default attribute values.
     *
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_COMPLETED,
    ];

    /**
     * The attributes that should be cast.
     *
     * Declared as a property rather than the `casts()` method so the casts
     * apply on Laravel 10, which has no such method.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    protected $casts = [
        'performance_score'    => 'integer',
        'accessibility_score'  => 'integer',
        'best_practices_score' => 'integer',
        'seo_score'            => 'integer',
        'lab_metrics'          => 'array',
        'field_data'           => 'array',
        'origin_field_data'    => 'array',
        'opportunities'        => 'array',
        'warnings'             => 'array',
        'raw_response'         => 'array',
        'fetched_at'           => 'immutable_datetime',
    ];

    /**
     * Build an unsaved result row from a parsed run.
     *
     * The raw response is only carried across when the caller asks for it —
     * a single payload runs to hundreds of kilobytes, so retaining it by
     * default would multiply the size of the history table by two orders of
     * magnitude.
     *
     * @since 1.0.0
     *
     * @param  TestResult  $result  The parsed run.
     * @param  int|PageSpeedUrl|null  $url  The monitored URL, when this was a scheduled run.
     * @param  bool|null  $storeRaw  Whether to keep the raw payload; null reads the config.
     *
     * @return static The unsaved model.
     */
    public static function fromTestResult(
        TestResult $result,
        PageSpeedUrl|int|null $url = null,
        ?bool $storeRaw = null,
    ): static {
        $model = new static();

        $model->pagespeed_url_id   = $url instanceof PageSpeedUrl ? $url->getKey() : $url;
        $model->url                = $result->url;
        $model->final_url          = $result->finalUrl;
        $model->strategy           = $result->strategy;
        $model->lab_metrics        = $result->labMetrics->toArray();
        $model->field_data         = $result->fieldData?->toArray();
        $model->origin_field_data  = $result->originFieldData?->toArray();
        $model->opportunities      = array_map(
            static fn ( Opportunity $opportunity ): array => $opportunity->toArray(),
            $result->opportunities,
        );
        $model->warnings           = $result->warnings();
        $model->lighthouse_version = $result->lighthouseVersion;
        $model->status             = self::STATUS_COMPLETED;
        $model->error_message      = null;
        $model->fetched_at         = $result->analyzedAt ?? CarbonImmutable::now();

        foreach ( self::SCORE_COLUMNS as $category => $column ) {
            $model->{$column} = $result->scores->get( $category );
        }

        self::logDroppedCategories( $result );

        $keepRaw = $storeRaw ?? (bool) config( 'pagespeed-insights.store_raw_response', false );

        $model->raw_response = $keepRaw ? $result->raw : null;

        return $model;
    }

    /**
     * Build an unsaved row recording a run that never produced a result.
     *
     * Named apart from {@see self::scopeFailed()} deliberately: Eloquent
     * resolves `PageSpeedResult::failed()` to a real static method before it
     * ever considers the scope, so sharing the name would silently break the
     * query scope at every call site.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL that was requested.
     * @param  string  $strategy  The form factor that was requested.
     * @param  string  $message  Why the run failed.
     * @param  int|PageSpeedUrl|null  $monitored  The monitored URL, when this was a scheduled run.
     *
     * @return static The unsaved model.
     */
    public static function fromFailure(
        string $url,
        string $strategy,
        string $message,
        PageSpeedUrl|int|null $monitored = null,
    ): static {
        $model = new static();

        $model->pagespeed_url_id = $monitored instanceof PageSpeedUrl ? $monitored->getKey() : $monitored;
        $model->url              = $url;
        $model->strategy         = $strategy;
        $model->status           = self::STATUS_FAILED;
        $model->error_message    = $message;
        $model->fetched_at       = CarbonImmutable::now();

        return $model;
    }

    /**
     * The monitored URL this result belongs to, when it was a scheduled run.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<PageSpeedUrl, $this> The relation.
     */
    public function pageSpeedUrl(): BelongsTo
    {
        return $this->belongsTo( PageSpeedUrl::class, 'pagespeed_url_id' );
    }

    /**
     * Results for one URL, optionally narrowed to one form factor.
     *
     * Matches on the denormalized `url` column rather than the foreign key so
     * that ad hoc runs and runs whose monitored row was later deleted stay
     * part of the URL's history.
     *
     * @since 1.0.0
     *
     * @param  Builder<PageSpeedResult>  $query  The query to constrain.
     * @param  PageSpeedUrl|string  $url  The URL, or the monitored row for it.
     * @param  string|null  $strategy  An optional form factor to narrow to.
     *
     * @return Builder<PageSpeedResult> The constrained query.
     */
    public function scopeForUrl( Builder $query, PageSpeedUrl|string $url, ?string $strategy = null ): Builder
    {
        $query->where( 'url', $url instanceof PageSpeedUrl ? $url->url : trim( $url ) );

        if ( null !== $strategy ) {
            $query->where( 'strategy', strtolower( trim( $strategy ) ) );
        }

        return $query;
    }

    /**
     * The most recent results for one URL, newest first.
     *
     * Ordered by `created_at` and then `id` so that two rows written inside
     * the same second — both strategies of one scheduled run, typically —
     * still come back in a stable order.
     *
     * @since 1.0.0
     *
     * @param  Builder<PageSpeedResult>  $query  The query to constrain.
     * @param  PageSpeedUrl|string  $url  The URL, or the monitored row for it.
     * @param  string|null  $strategy  An optional form factor to narrow to.
     *
     * @return Builder<PageSpeedResult> The constrained query.
     */
    public function scopeLatestFor( Builder $query, PageSpeedUrl|string $url, ?string $strategy = null ): Builder
    {
        return $query->forUrl( $url, $strategy )
            ->orderByDesc( 'created_at' )
            ->orderByDesc( 'id' );
    }

    /**
     * Only runs that produced a result.
     *
     * @since 1.0.0
     *
     * @param  Builder<PageSpeedResult>  $query  The query to constrain.
     *
     * @return Builder<PageSpeedResult> The constrained query.
     */
    public function scopeCompleted( Builder $query ): Builder
    {
        return $query->where( 'status', self::STATUS_COMPLETED );
    }

    /**
     * Only runs that failed.
     *
     * @since 1.0.0
     *
     * @param  Builder<PageSpeedResult>  $query  The query to constrain.
     *
     * @return Builder<PageSpeedResult> The constrained query.
     */
    public function scopeFailed( Builder $query ): Builder
    {
        return $query->where( 'status', self::STATUS_FAILED );
    }

    /**
     * Whether this run finished.
     *
     * @since 1.0.0
     *
     * @return bool True when the status is completed.
     */
    public function isCompleted(): bool
    {
        return self::STATUS_COMPLETED === $this->status;
    }

    /**
     * Whether this run failed outright.
     *
     * @since 1.0.0
     *
     * @return bool True when the status is failed.
     */
    public function isFailed(): bool
    {
        return self::STATUS_FAILED === $this->status;
    }

    /**
     * Whether anything about this run is worth explaining.
     *
     * Covers Lighthouse's own run warnings and everything the parser
     * tolerated, including the absence of CrUX data — which is ordinary for
     * a low-traffic page but still the reason the Core Web Vitals panel is
     * empty.
     *
     * @since 1.0.0
     *
     * @return bool True when `warnings` records anything.
     */
    public function hasWarnings(): bool
    {
        if ( true === ( $this->warnings[ 'missing_field_data' ] ?? false ) ) {
            return true;
        }

        foreach ( [ 'run_warnings', 'missing_categories', 'unrecognized_categories', 'missing_metrics' ] as $key ) {
            if ( [] !== $this->warningStrings( $key ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this run completed but lost data on the way.
     *
     * Narrower than {@see self::hasWarnings()} on purpose. Absent CrUX data
     * is normal and does not make a run degraded; a category or lab metric
     * that was requested and did not come back does, because that is a hole
     * in the history the trend chart will draw straight through.
     *
     * @since 1.0.0
     *
     * @return bool True when the run completed with data missing.
     */
    public function wasDegraded(): bool
    {
        if ( ! $this->isCompleted() ) {
            return false;
        }

        foreach ( [ 'missing_categories', 'unrecognized_categories', 'missing_metrics' ] as $key ) {
            if ( [] !== $this->warningStrings( $key ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything the run warned about, as a flat list of human-readable
     * lines, ready for a UI that wants to explain a thin-looking row.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The warning lines.
     */
    public function warningList(): array
    {
        $lines = $this->warningStrings( 'run_warnings' );

        $templates = [
            'missing_categories'      => 'PageSpeed did not return the ":name" category, so its score is null for this run.',
            'unrecognized_categories' => 'PageSpeed returned a ":name" category this package has no column for, so it was not stored.',
            'missing_metrics'         => 'PageSpeed did not return the ":name" lab metric.',
        ];

        foreach ( $templates as $key => $template ) {
            foreach ( $this->warningStrings( $key ) as $name ) {
                $lines[] = __( $template, [ 'name' => $name ] );
            }
        }

        if ( true === ( $this->warnings[ 'missing_field_data' ] ?? false ) ) {
            $lines[] = __( 'The Chrome UX Report had no real-user data for this page or its origin.' );
        }

        return $lines;
    }

    /**
     * The scores as a set, rebuilt from the stored columns.
     *
     * @since 1.0.0
     *
     * @return ScoreSet The stored scores.
     */
    public function scores(): ScoreSet
    {
        $missing = $this->warningStrings( 'missing_categories' );
        $scores  = [];

        foreach ( self::SCORE_COLUMNS as $category => $column ) {
            // A category the response never carried is left off the set
            // entirely, so that ScoreSet::has() can tell "absent" from
            // "present but unscored" — both of which store as a null column.
            if ( in_array( $category, $missing, true ) ) {
                continue;
            }

            $scores[ $category ] = null === $this->{$column} ? null : (int) $this->{$column};
        }

        return new ScoreSet( $scores );
    }

    /**
     * Rebuild the parsed run this row was written from.
     *
     * Lossless for everything the schema keeps. Categories outside
     * {@see self::SCORE_COLUMNS} have no column and do not come back — they
     * are named in `warnings.unrecognized_categories` instead, which is what
     * the round trip preserves.
     *
     * @since 1.0.0
     *
     * @return TestResult The rebuilt result.
     */
    public function toTestResult(): TestResult
    {
        return new TestResult(
            url: (string) $this->url,
            strategy: (string) $this->strategy,
            scores: $this->scores(),
            labMetrics: new LabMetrics(
                self::hydrateLabMetrics( $this->lab_metrics ),
                $this->warningStrings( 'missing_metrics' ),
            ),
            fieldData: self::hydrateFieldData( $this->field_data ),
            originFieldData: self::hydrateFieldData( $this->origin_field_data ),
            opportunities: array_map(
                static fn ( array $opportunity ): Opportunity => self::hydrateOpportunity( $opportunity ),
                array_values( array_filter( (array) ( $this->opportunities ?? [] ), 'is_array' ) ),
            ),
            finalUrl: $this->final_url,
            lighthouseVersion: $this->lighthouse_version,
            analyzedAt: $this->fetched_at,
            runWarnings: $this->warningStrings( 'run_warnings' ),
            unrecognizedCategories: $this->warningStrings( 'unrecognized_categories' ),
            missingCategories: $this->warningStrings( 'missing_categories' ),
            missingMetrics: $this->warningStrings( 'missing_metrics' ),
            raw: $this->raw_response,
        );
    }

    /**
     * One list out of the `warnings` json, reduced to its non-empty strings.
     *
     * Everything the column holds is user-visible or fed back into a DTO
     * that types these as strings, so anything else in there is discarded
     * rather than propagated.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The warnings key to read.
     *
     * @return array<int, string> The stored strings, or an empty list.
     */
    protected function warningStrings( string $key ): array
    {
        $value = $this->warnings[ $key ] ?? [];

        if ( ! is_array( $value ) ) {
            return [];
        }

        $strings = [];

        foreach ( $value as $entry ) {
            if ( is_string( $entry ) && '' !== trim( $entry ) ) {
                $strings[] = $entry;
            }
        }

        return $strings;
    }

    /**
     * Rebuild the lab metric map from its stored form.
     *
     * JSON has one number type, so a value the parser produced as 45.0 comes
     * back out of the column as the integer 45. Restoring the float here is
     * what makes the DTO round trip type-exact instead of merely
     * approximately equal.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>|null  $stored  The stored json.
     *
     * @return array<string, array{value: float|null, display: string|null}> The metric map.
     */
    protected static function hydrateLabMetrics( ?array $stored ): array
    {
        $metrics = [];

        foreach ( (array) $stored as $auditId => $metric ) {
            if ( ! is_array( $metric ) ) {
                continue;
            }

            $value = $metric[ 'value' ] ?? null;

            $metrics[ (string) $auditId ] = [
                'value'   => is_numeric( $value ) ? (float) $value : null,
                'display' => isset( $metric[ 'display' ] ) ? (string) $metric[ 'display' ] : null,
            ];
        }

        return $metrics;
    }

    /**
     * Rebuild a field data set from its stored form.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>|null  $stored  The stored json.
     *
     * @return FieldData|null The set, or null when nothing was stored.
     */
    protected static function hydrateFieldData( ?array $stored ): ?FieldData
    {
        if ( null === $stored || [] === $stored ) {
            return null;
        }

        return new FieldData(
            metrics: is_array( $stored[ 'metrics' ] ?? null ) ? $stored[ 'metrics' ] : [],
            overallCategory: isset( $stored[ 'overall_category' ] ) ? (string) $stored[ 'overall_category' ] : null,
            id: isset( $stored[ 'id' ] ) ? (string) $stored[ 'id' ] : null,
            originFallback: true === ( $stored[ 'origin_fallback' ] ?? false ),
            isOriginLevel: true === ( $stored[ 'origin_level' ] ?? false ),
        );
    }

    /**
     * Rebuild one opportunity from its stored form.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $stored  The stored opportunity.
     *
     * @return Opportunity The rebuilt opportunity.
     */
    protected static function hydrateOpportunity( array $stored ): Opportunity
    {
        return new Opportunity(
            id: (string) ( $stored[ 'id' ] ?? '' ),
            title: (string) ( $stored[ 'title' ] ?? '' ),
            score: is_numeric( $stored[ 'score' ] ?? null ) ? (float) $stored[ 'score' ] : null,
            savingsMs: is_numeric( $stored[ 'savings_ms' ] ?? null ) ? (float) $stored[ 'savings_ms' ] : null,
            displayValue: isset( $stored[ 'display_value' ] ) ? (string) $stored[ 'display_value' ] : null,
            metricSavings: self::hydrateMetricSavings( $stored[ 'metric_savings' ] ?? null ),
            description: isset( $stored[ 'description' ] ) ? (string) $stored[ 'description' ] : null,
        );
    }

    /**
     * Rebuild an opportunity's per-metric savings from its stored form.
     *
     * Floats, for the same reason as {@see self::hydrateLabMetrics()}: these
     * feed {@see Opportunity::weight()}, which sorts the list a UI shows.
     *
     * @since 1.0.0
     *
     * @param  mixed  $stored  The stored map.
     *
     * @return array<string, float> The numeric entries.
     */
    protected static function hydrateMetricSavings( mixed $stored ): array
    {
        if ( ! is_array( $stored ) ) {
            return [];
        }

        $savings = [];

        foreach ( $stored as $metric => $value ) {
            if ( is_numeric( $value ) ) {
                $savings[ (string) $metric ] = (float) $value;
            }
        }

        return $savings;
    }

    /**
     * Log every category that was parsed but has no column to be stored in.
     *
     * The parser already logged these at parse time; logging again at write
     * time is deliberate, because the two are separated by a queue in the
     * scheduled path and only this one proves the data is actually gone.
     *
     * @since 1.0.0
     *
     * @param  TestResult  $result  The parsed run.
     *
     * @return void
     */
    protected static function logDroppedCategories( TestResult $result ): void
    {
        foreach ( $result->unrecognizedCategories as $category ) {
            Log::warning(
                'Storing a PageSpeed result without a Lighthouse category this package has no column for. The score is recorded in the result warnings but not as a column.',
                [
                    'url'      => $result->url,
                    'strategy' => $result->strategy,
                    'category' => $category,
                ],
            );
        }
    }

    /**
     * Create a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return PageSpeedResultFactory The model factory.
     */
    protected static function newFactory(): PageSpeedResultFactory
    {
        return PageSpeedResultFactory::new();
    }
}
