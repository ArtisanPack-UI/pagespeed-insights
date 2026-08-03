<?php

/**
 * Lighthouse opportunities table Livewire component.
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
use ArtisanPackUI\PageSpeedInsights\Data\Opportunity;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Support\ScoreBands;
use ArtisanPackUI\PageSpeedInsights\Support\UiComponentsInstalled;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlNormalizer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * What Lighthouse says is worth fixing on one URL, ordered by what fixing it
 * would buy.
 *
 * ### An empty table is three different pieces of news
 *
 * The list this component renders is already pruned — the parser keeps the ten
 * heaviest actionable audits and drops the rest — so an empty list is
 * genuinely empty, and that is exactly why it cannot be rendered as a blank
 * area. There are three ways to arrive at no rows, and only one of them is
 * good news:
 *
 * 1. **{@see self::STATE_NOT_MEASURED}** — the run never asked for the
 *    performance category, so Lighthouse had no opportunity audits to report.
 *    Nothing was found because nothing was looked for. Rendering this as "no
 *    opportunities" would tell an operator their page is clean on the strength
 *    of a test that never examined it.
 * 2. **{@see self::STATE_NONE}** — performance *was* measured and Lighthouse
 *    found nothing above its own reporting threshold. This is the good one,
 *    and it is worth saying out loud rather than leaving as whitespace.
 * 3. **{@see self::STATE_FAILED}** — the last run did not produce a result at
 *    all, so there is nothing to have found.
 *
 * ### Ordering
 *
 * Rows are sorted by {@see Opportunity::weight()} descending, which takes the
 * larger of Lighthouse's overall estimate and its largest per-metric estimate.
 * The parser sorts the list the same way before storing it, but the sort is
 * repeated here rather than trusted: rows written by an older version of this
 * package, or reshaped by a listener on `ap.pageSpeed.opportunities`, can be
 * in any order at all, and a table headed "estimated saving" whose largest
 * number is halfway down reads as broken.
 *
 * ### Where the table gets its authority
 *
 * `$url` and `$strategy` are `#[Locked]` for the same reason they are on
 * {@see ScoreCard}: they decide whose history is read, and a viewer must not
 * be able to retarget the component from the request payload.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class OpportunitiesTable extends Component
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
     * The most recent run failed, so there is nothing to have found.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_FAILED = 'failed';

    /**
     * The run did not measure performance, so no audits were collected.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_NOT_MEASURED = 'not-measured';

    /**
     * Performance was measured and Lighthouse found nothing worth listing.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_NONE = 'none';

    /**
     * There are opportunities to show.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_LOADED = 'loaded';

    /**
     * The URL these opportunities belong to.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Locked]
    public string $url = '';

    /**
     * The form factor these opportunities belong to.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Locked]
    public string $strategy = PageSpeedRequest::STRATEGY_MOBILE;

    /**
     * Which of the five states this table is in.
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
     * The id of the result the rows were read from, or null.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    #[Locked]
    public ?int $resultId = null;

    /**
     * The opportunity rows, heaviest first.
     *
     * @since 1.0.0
     *
     * @var array<int, array{id: string, title: string, description: string|null, displayValue: string|null, savingsMs: float|null, savings: string|null, score: int|null, band: string|null, color: string|null, bandLabel: string|null}>
     */
    #[Locked]
    public array $rows = [];

    /**
     * Why the last run failed, as this package wrote it.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $errorMessage = null;

    /**
     * When the run the rows came from was fetched, already formatted.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $fetchedAt = null;

    /**
     * Set the table up for one URL and form factor.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL to list opportunities for.
     * @param  string|null  $strategy  mobile or desktop; null uses mobile.
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
     * Reload the opportunities from the latest stored run.
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
     * This table has no run button and no poll of its own — both live on
     * {@see ScoreCard} — so without this it would keep showing the previous
     * run's opportunities beside a freshly updated score card, which reads as
     * a page that half-refreshed.
     *
     * The URL and form factor are checked rather than trusted: a page may
     * carry several sets of components, and a run against one says nothing
     * about the others.
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
     * Render the table.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        return view( 'pagespeed-insights::livewire.opportunities-table' );
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
     * Copy a stored run's opportunities onto the component.
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
        $this->rows         = [];
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

        $this->rows = self::buildRows( $result->toTestResult()->opportunities );

        if ( [] !== $this->rows ) {
            $this->state = self::STATE_LOADED;

            return;
        }

        // Nothing found and nothing looked for are opposite readings of the
        // same empty list, so the performance category decides which one this
        // is. ScoreSet::has() is what tells a category the response never
        // carried from one it carried unscored.
        $this->state = $result->scores()->has( 'performance' )
            ? self::STATE_NONE
            : self::STATE_NOT_MEASURED;
    }

    /**
     * Build the table rows from a run's opportunities, heaviest first.
     *
     * @since 1.0.0
     *
     * @param  array<int, Opportunity>  $opportunities  The stored opportunities.
     *
     * @return array<int, array{id: string, title: string, description: string|null, displayValue: string|null, savingsMs: float|null, savings: string|null, score: int|null, band: string|null, color: string|null, bandLabel: string|null}> The rows, in display order.
     */
    protected static function buildRows( array $opportunities ): array
    {
        usort(
            $opportunities,
            static fn ( Opportunity $a, Opportunity $b ): int => $b->weight() <=> $a->weight(),
        );

        $rows = [];

        foreach ( $opportunities as $opportunity ) {
            $score  = self::auditScore( $opportunity->score );
            $band   = ScoreBands::forScore( $score );
            $weight = $opportunity->weight();

            $rows[] = [
                'id'           => $opportunity->id,
                'title'        => '' === $opportunity->title ? $opportunity->id : $opportunity->title,
                'description'  => $opportunity->description,
                'displayValue' => $opportunity->displayValue,
                'savingsMs'    => $weight > 0.0 ? $weight : null,
                'savings'      => self::formatSavings( $weight ),
                'score'        => $score,
                'band'         => $band,
                'color'        => ScoreBands::color( $band ),
                'bandLabel'    => ScoreBands::label( $band ),
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

    /**
     * An estimated saving, written in the unit a reader expects.
     *
     * Sub-second savings stay in milliseconds because "0.4 s" reads as a
     * rounding artefact where "400 ms" reads as a measurement.
     *
     * @since 1.0.0
     *
     * @param  float  $milliseconds  The estimated saving.
     *
     * @return string|null The formatted saving, or null when there is none to state.
     */
    protected static function formatSavings( float $milliseconds ): ?string
    {
        if ( $milliseconds <= 0.0 ) {
            return null;
        }

        if ( $milliseconds < 1000.0 ) {
            return __( ':value ms', [ 'value' => number_format( $milliseconds ) ] );
        }

        return __( ':value s', [ 'value' => number_format( $milliseconds / 1000, 1 ) ] );
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
