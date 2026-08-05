<?php

/**
 * Turns stored results into the JSON the HTTP endpoints serve.
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

use ArtisanPackUI\PageSpeedInsights\Data\FieldData;
use ArtisanPackUI\PageSpeedInsights\Data\LabMetrics;
use ArtisanPackUI\PageSpeedInsights\Data\Opportunity;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Support\CategoryTranslator;
use ArtisanPackUI\PageSpeedInsights\Support\ScoreBands;

/**
 * One place the shape of every result payload is decided.
 *
 * Four endpoints answer about the same stored row from different angles, and a
 * result summary spelled three slightly different ways is how a front end ends
 * up with three slightly different bugs. So the fragments live here and the
 * controllers assemble them.
 *
 * ### What is and is not translated
 *
 * Bands are computed server-side; labels and colours are not sent. A band is a
 * fact about where a number falls against Google's published thresholds, and
 * duplicating that arithmetic in every client is how two surfaces of the same
 * dashboard come to disagree about whether a page is green. A label is a
 * presentation choice, and a JSON endpoint that bakes one in hands its caller a
 * string in the server's locale rather than the reader's.
 *
 * The one exception is `warnings` and `errorMessage`, which are already
 * prose — written by this package when the run was stored, in whatever locale
 * was active then — and have no machine-readable form to offer instead.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class ResultPresenter
{
    /**
     * The Core Web Vitals a field data payload carries, in display order.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const VITALS = [
        'largest_contentful_paint',
        'interaction_to_next_paint',
        'cumulative_layout_shift',
    ];

    /**
     * Which run a payload describes, without any of its measurements.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The stored run.
     *
     * @return array{id: int|null, url: string, finalUrl: string|null, strategy: string, status: string, lighthouseVersion: string|null, fetchedAt: string|null, degraded: bool, warnings: array<int, string>, errorMessage: string|null} The summary.
     */
    public function summary( PageSpeedResult $result ): array
    {
        return [
            'id'                => $result->getKey(),
            'url'               => (string) $result->url,
            'finalUrl'          => $result->final_url,
            'strategy'          => (string) $result->strategy,
            'status'            => (string) $result->status,
            'lighthouseVersion' => $result->lighthouse_version,
            'fetchedAt'         => $result->fetched_at?->toIso8601String(),
            'degraded'          => $result->wasDegraded(),
            'warnings'          => $result->warningList(),
            'errorMessage'      => $result->error_message,
        ];
    }

    /**
     * The four category scores, banded.
     *
     * A category the response never carried is left out entirely rather than
     * sent as a null, so a client can tell "not measured" from "measured and
     * unscored" — the distinction {@see PageSpeedResult::scores()} preserves,
     * and the one that decides whether an empty opportunities list is good
     * news.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The stored run.
     *
     * @return array<int, array{category: string, score: int|null, band: string|null}> The scores, in display order.
     */
    public function scores( PageSpeedResult $result ): array
    {
        $scores = $result->scores();
        $rows   = [];

        foreach ( CategoryTranslator::KNOWN as $category ) {
            if ( ! $scores->has( $category ) ) {
                continue;
            }

            $score = $scores->get( $category );

            $rows[] = [
                'category' => $category,
                'score'    => $score,
                'band'     => ScoreBands::forScore( $score ),
            ];
        }

        return $rows;
    }

    /**
     * The Lighthouse lab metrics, keyed by audit id.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The stored run.
     *
     * @return array<string, array{value: float|null, display: string|null}> The metrics, in display order.
     */
    public function labMetrics( PageSpeedResult $result ): array
    {
        $stored  = (array) ( $result->lab_metrics ?? [] );
        $metrics = [];

        foreach ( LabMetrics::AUDIT_IDS as $auditId ) {
            $metric = $stored[ $auditId ] ?? null;

            if ( ! is_array( $metric ) ) {
                continue;
            }

            $value = $metric[ 'value' ] ?? null;

            $metrics[ $auditId ] = [
                'value'   => is_numeric( $value ) ? (float) $value : null,
                'display' => isset( $metric[ 'display' ] ) ? (string) $metric[ 'display' ] : null,
            ];
        }

        return $metrics;
    }

    /**
     * One CrUX field data set, banded.
     *
     * @since 1.0.0
     *
     * @param  FieldData|null  $fieldData  The set, or null when CrUX had none.
     *
     * @return array{id: string|null, overallCategory: string|null, originFallback: bool, originLevel: bool, vitals: array<int, array{metric: string, value: int|null, band: string|null, category: string|null}>}|null The payload, or null when there is no data.
     */
    public function fieldData( ?FieldData $fieldData ): ?array
    {
        // A set carrying an overall category and no measurements is CrUX
        // answering with a shell, and three empty rows read as three
        // measurements that came back blank rather than as no data at all.
        if ( ! $fieldData instanceof FieldData || [] === $fieldData->all() ) {
            return null;
        }

        $vitals = [];

        foreach ( self::VITALS as $metric ) {
            $value = $fieldData->percentile( $metric );

            $vitals[] = [
                'metric'   => $metric,
                'value'    => $value,
                'band'     => ScoreBands::forVital( $metric, $value ),
                'category' => $fieldData->category( $metric ),
            ];
        }

        return [
            'id'              => $fieldData->id(),
            'overallCategory' => $fieldData->overallCategory(),
            'originFallback'  => $fieldData->isOriginFallback(),
            'originLevel'     => $fieldData->isOriginLevel(),
            'vitals'          => $vitals,
        ];
    }

    /**
     * The stored opportunities, heaviest first.
     *
     * Re-sorted rather than trusted, for the reason {@see \ArtisanPackUI\PageSpeedInsights\Livewire\OpportunitiesTable}
     * re-sorts them: rows written by an older version of this package, or
     * reshaped by a listener on `ap.pageSpeed.opportunities`, can be in any
     * order at all.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The stored run.
     *
     * @return array<int, array{id: string, title: string, description: string|null, displayValue: string|null, savingsMs: float|null, score: int|null, band: string|null}> The opportunities, heaviest first.
     */
    public function opportunities( PageSpeedResult $result ): array
    {
        return $this->opportunityRows( $result->toTestResult()->opportunities );
    }

    /**
     * Everything one stored run holds, in one payload.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The stored run.
     *
     * @return array<string, mixed> The full payload.
     */
    public function full( PageSpeedResult $result ): array
    {
        $payload = $this->summary( $result );

        if ( $result->isFailed() ) {
            return $payload;
        }

        // Parsed once and reused. `toTestResult()` rebuilds every DTO the row
        // holds — field data, lab metrics, and each opportunity — so calling
        // it again for the opportunities alone would do all of that twice for
        // one response.
        $parsed = $result->toTestResult();

        return $payload + [
            'scores'        => $this->scores( $result ),
            'labMetrics'    => $this->labMetrics( $result ),
            'fieldData'     => [
                'percentile' => FieldData::PERCENTILE,
                'page'       => $this->fieldData( $parsed->fieldData ),
                'origin'     => $this->fieldData( $parsed->originFieldData ),
            ],
            'opportunities' => $this->opportunityRows( $parsed->opportunities ),
        ];
    }

    /**
     * Shape a parsed run's opportunities for the wire, heaviest first.
     *
     * @since 1.0.0
     *
     * @param  array<int, Opportunity>  $opportunities  The parsed opportunities.
     *
     * @return array<int, array{id: string, title: string, description: string|null, displayValue: string|null, savingsMs: float|null, score: int|null, band: string|null}> The opportunities, heaviest first.
     */
    protected function opportunityRows( array $opportunities ): array
    {
        usort(
            $opportunities,
            static fn ( Opportunity $a, Opportunity $b ): int => $b->weight() <=> $a->weight(),
        );

        $rows = [];

        foreach ( $opportunities as $opportunity ) {
            $score  = self::auditScore( $opportunity->score );
            $weight = $opportunity->weight();

            $rows[] = [
                'id'           => $opportunity->id,
                'title'        => '' === $opportunity->title ? $opportunity->id : $opportunity->title,
                'description'  => $opportunity->description,
                'displayValue' => $opportunity->displayValue,
                'savingsMs'    => $weight > 0.0 ? $weight : null,
                'score'        => $score,
                'band'         => ScoreBands::forScore( $score ),
            ];
        }

        return $rows;
    }

    /**
     * An audit's 0-1 score on the 0-100 scale the bands are defined against.
     *
     * @since 1.0.0
     *
     * @param  float|null  $score  Lighthouse's 0-1 audit score, or null when unscored.
     *
     * @return int|null The 0-100 score, or null when the audit was unscored.
     */
    protected static function auditScore( ?float $score ): ?int
    {
        if ( null === $score ) {
            return null;
        }

        return (int) round( max( 0.0, min( 1.0, $score ) ) * 100 );
    }
}
