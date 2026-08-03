<?php

/**
 * Core Web Vitals card Livewire component.
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
use ArtisanPackUI\PageSpeedInsights\Data\FieldData;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Support\ScoreBands;
use ArtisanPackUI\PageSpeedInsights\Support\UiComponentsInstalled;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlNormalizer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The three Core Web Vitals — LCP, INP, CLS — from CrUX field data, banded
 * against Google's own thresholds.
 *
 * ### Page level is not origin level
 *
 * CrUX has no data for low-traffic pages, and there are two quite different
 * ways that shows up. Collapsing them is a correctness problem, not a
 * presentation one, because origin-level numbers describe the whole site and
 * showing them as if they described this page misrepresents the page:
 *
 * - **{@see self::STATE_NO_FIELD_DATA}** — CrUX has nothing for this page and
 *   nothing for the origin either. There is no measurement to show.
 * - **{@see self::STATE_ORIGIN_LEVEL}** — the numbers on screen describe the
 *   site as a whole, because this page alone has too little traffic. They are
 *   real numbers; they are just about something broader than the card's URL,
 *   and the card says so.
 *
 * ### The percentile
 *
 * CrUX reports Core Web Vitals at a percentile the API's own discovery
 * document describes incorrectly. The label is read from
 * {@see FieldData::PERCENTILE} rather than written into the view, so a
 * correction in the parser reaches the card without anyone editing Blade.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class CoreWebVitalsCard extends Component
{
    /**
     * No run has been stored for this URL and form factor yet.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_EMPTY = 'empty';

    /**
     * The most recent run failed, so there is no field data to read.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_FAILED = 'failed';

    /**
     * The run completed, but CrUX has no data for the page or the origin.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_NO_FIELD_DATA = 'no-field-data';

    /**
     * The vitals on screen describe the origin, not this page.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_ORIGIN_LEVEL = 'origin-level';

    /**
     * Page-level field data for this exact URL.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_LOADED = 'loaded';

    /**
     * The Core Web Vitals this card shows, in display order.
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
     * The URL these vitals describe.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Locked]
    public string $url = '';

    /**
     * The form factor whose stored run the field data is read from.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Locked]
    public string $strategy = PageSpeedRequest::STRATEGY_MOBILE;

    /**
     * Which of the five states this card is in.
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
     * The id of the result the vitals were read from, or null.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    #[Locked]
    public ?int $resultId = null;

    /**
     * The three vitals, in display order.
     *
     * @since 1.0.0
     *
     * @var array<int, array{metric: string, label: string, abbreviation: string, value: int|null, display: string|null, band: string|null, color: string|null, bandLabel: string|null, available: bool}>
     */
    public array $vitals = [];

    /**
     * Whether the numbers on screen describe the origin rather than the page.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $originLevel = false;

    /**
     * What the numbers on screen describe — the page URL, or the origin.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $dataSubject = null;

    /**
     * Which percentile the values represent.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $percentile = FieldData::PERCENTILE;

    /**
     * Why the last run failed, as this package wrote it.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $errorMessage = null;

    /**
     * When the run the vitals came from was fetched, already formatted.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $fetchedAt = null;

    /**
     * Set the card up for one URL.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL to show vitals for.
     * @param  string|null  $strategy  The form factor whose run to read; null uses mobile.
     *
     * @return void
     */
    public function mount( string $url = '', ?string $strategy = null ): void
    {
        $this->url      = UrlNormalizer::normalize( $url ) ?? trim( $url );
        $this->strategy = self::normalizeStrategy( $strategy );

        $this->refresh();
    }

    /**
     * Reload the vitals from the latest stored run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->uiComponentsInstalled = UiComponentsInstalled::check();

        $this->apply( $this->latestResult() );
    }

    /**
     * Pick up a run the score card was waiting on.
     *
     * This card has no way of knowing a test is in flight — the run button
     * and the poll both live on {@see ScoreCard} — so without this it would
     * keep showing whatever it read at mount until the page was reloaded.
     * Two cards describing the same URL disagreeing about whether a test has
     * ever run reads as a broken page, not as a stale one.
     *
     * The URL and form factor are checked rather than trusted: a page may
     * carry several pairs of cards, and a run against one of them says
     * nothing about the others.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL the finished run was for.
     * @param  string  $strategy  The form factor the finished run was for.
     *
     * @return void
     */
    #[On( ScoreCard::EVENT_RESULT_STORED )]
    public function onResultStored( string $url = '', string $strategy = '' ): void
    {
        if ( $url !== $this->url || $strategy !== $this->strategy ) {
            return;
        }

        $this->refresh();
    }

    /**
     * Render the card.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        return view( 'pagespeed-insights::livewire.core-web-vitals-card' );
    }

    /**
     * The most recent stored run for this URL and form factor.
     *
     * @since 1.0.0
     *
     * @return PageSpeedResult|null The row, or null when there is none.
     */
    protected function latestResult(): ?PageSpeedResult
    {
        if ( '' === $this->url ) {
            return null;
        }

        return PageSpeedResult::query()
            ->latestFor( $this->url, $this->strategy )
            ->first();
    }

    /**
     * Copy a stored run's field data onto the component.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult|null  $result  The row to read.
     *
     * @return void
     */
    protected function apply( ?PageSpeedResult $result ): void
    {
        $this->resultId     = $result?->getKey();
        $this->vitals       = [];
        $this->originLevel  = false;
        $this->dataSubject  = null;
        $this->errorMessage = null;
        $this->fetchedAt    = null;

        if ( null === $result ) {
            $this->state = self::STATE_EMPTY;

            return;
        }

        $this->fetchedAt = $result->fetched_at?->toDayDateTimeString();

        if ( $result->isFailed() ) {
            $this->state        = self::STATE_FAILED;
            $this->errorMessage = $result->error_message
                ?? __( 'The run failed and did not record a reason.' );

            return;
        }

        $fieldData = $this->resolveFieldData( $result );

        if ( null === $fieldData ) {
            $this->state = self::STATE_NO_FIELD_DATA;

            return;
        }

        $this->originLevel = $fieldData->isOriginLevel() || $fieldData->isOriginFallback();
        $this->dataSubject = $fieldData->id();
        $this->vitals      = self::buildVitals( $fieldData );
        $this->state       = $this->originLevel ? self::STATE_ORIGIN_LEVEL : self::STATE_LOADED;
    }

    /**
     * The field data to render, preferring the page over the origin.
     *
     * Page-level data is what the card claims to show, so it is used whenever
     * it exists. Origin-level data is a fallback that is worth showing —
     * knowing the site is slow is better than knowing nothing — but only
     * while labelled as what it is.
     *
     * A set with no metrics in it counts as absent. CrUX occasionally
     * answers with a shell carrying an overall category and nothing to back
     * it up, and three empty rows are a worse "not enough data" state than
     * the one written for the purpose.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The completed run.
     *
     * @return FieldData|null The set to render, or null when there is none.
     */
    protected function resolveFieldData( PageSpeedResult $result ): ?FieldData
    {
        $parsed = $result->toTestResult();

        foreach ( [ $parsed->fieldData, $parsed->originFieldData ] as $candidate ) {
            if ( $candidate instanceof FieldData && [] !== $candidate->all() ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Build the three vital rows from a field data set.
     *
     * @since 1.0.0
     *
     * @param  FieldData  $fieldData  The set to read.
     *
     * @return array<int, array{metric: string, label: string, abbreviation: string, value: int|null, display: string|null, band: string|null, color: string|null, bandLabel: string|null, available: bool}> The rows, in display order.
     */
    protected static function buildVitals( FieldData $fieldData ): array
    {
        $vitals = [];

        foreach ( self::VITALS as $metric ) {
            $value = $fieldData->percentile( $metric );
            $band  = ScoreBands::forVital( $metric, $value );

            $vitals[] = [
                'metric'       => $metric,
                'label'        => self::vitalLabel( $metric ),
                'abbreviation' => self::vitalAbbreviation( $metric ),
                'value'        => $value,
                'display'      => self::formatVital( $metric, $value ),
                'band'         => $band,
                'color'        => ScoreBands::color( $band ),
                'bandLabel'    => ScoreBands::label( $band ),
                'available'    => null !== $value,
            ];
        }

        return $vitals;
    }

    /**
     * A vital's measurement, written in the unit a reader expects.
     *
     * LCP and INP arrive in milliseconds; LCP is conventionally read in
     * seconds, INP in milliseconds. CLS arrives multiplied by 100 and is
     * read as the unitless score it started as.
     *
     * @since 1.0.0
     *
     * @param  string  $metric  A canonical metric key.
     * @param  int|null  $value  The measurement, or null when absent.
     *
     * @return string|null The formatted value, or null when there is none.
     */
    protected static function formatVital( string $metric, ?int $value ): ?string
    {
        if ( null === $value ) {
            return null;
        }

        return match ( $metric ) {
            'largest_contentful_paint'  => number_format( $value / 1000, 1 ) . ' s',
            'interaction_to_next_paint' => number_format( $value ) . ' ms',
            'cumulative_layout_shift'   => number_format( $value / 100, 2 ),
            default                     => (string) $value,
        };
    }

    /**
     * A vital's full name, written for a human.
     *
     * @since 1.0.0
     *
     * @param  string  $metric  A canonical metric key.
     *
     * @return string The translated label.
     */
    protected static function vitalLabel( string $metric ): string
    {
        return match ( $metric ) {
            'largest_contentful_paint'  => __( 'Largest Contentful Paint' ),
            'interaction_to_next_paint' => __( 'Interaction to Next Paint' ),
            'cumulative_layout_shift'   => __( 'Cumulative Layout Shift' ),
            default                     => $metric,
        };
    }

    /**
     * A vital's initialism.
     *
     * Not translated: LCP, INP, and CLS are Google's own identifiers for
     * these metrics and are used untranslated in every locale's
     * documentation, so localising them would make the card harder to match
     * against the tool it mirrors rather than easier.
     *
     * @since 1.0.0
     *
     * @param  string  $metric  A canonical metric key.
     *
     * @return string The initialism.
     */
    protected static function vitalAbbreviation( string $metric ): string
    {
        return match ( $metric ) {
            'largest_contentful_paint'  => 'LCP',
            'interaction_to_next_paint' => 'INP',
            'cumulative_layout_shift'   => 'CLS',
            default                     => $metric,
        };
    }

    /**
     * Reduce a requested form factor to one this package tests.
     *
     * @since 1.0.0
     *
     * @param  string|null  $strategy  The requested form factor.
     *
     * @return string mobile or desktop.
     */
    protected static function normalizeStrategy( ?string $strategy ): string
    {
        $normalized = strtolower( trim( (string) $strategy ) );

        return PageSpeedRequest::STRATEGY_DESKTOP === $normalized
            ? PageSpeedRequest::STRATEGY_DESKTOP
            : PageSpeedRequest::STRATEGY_MOBILE;
    }
}
