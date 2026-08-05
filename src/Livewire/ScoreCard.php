<?php

/**
 * PageSpeed category score card Livewire component.
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
use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;
use ArtisanPackUI\PageSpeedInsights\Exceptions\PageSpeedApiException;
use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Support\CategoryTranslator;
use ArtisanPackUI\PageSpeedInsights\Support\ScoreBands;
use ArtisanPackUI\PageSpeedInsights\Support\UiComponentsInstalled;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The four Lighthouse category gauges for one URL and form factor, read from
 * the most recent stored run.
 *
 * ### The states are not one state
 *
 * The single worst thing this component could do is render every kind of
 * nothing the same way. An empty card reads as "no test has run yet", and a
 * broken configuration that renders as an empty card is a broken
 * configuration nobody notices — on a dashboard somebody looks at daily.
 *
 * So there are four, and they say different things:
 *
 * 1. **{@see self::STATE_NO_API_KEY}** — the default state of a fresh
 *    install, not an edge case. Keyless PageSpeed has a daily quota of zero,
 *    so every application that has not set `PAGESPEED_API_KEY` lands here.
 *    It names the environment variable and says where to get a key.
 * 2. **{@see self::STATE_EMPTY}** — genuinely nothing yet. Offers the run.
 * 3. **{@see self::STATE_FAILED}** — the last run failed, and the stored
 *    `error_message` is shown verbatim.
 * 4. **{@see self::STATE_DEGRADED}** — the last run completed but lost data
 *    on the way. Scores render, and so do the warnings explaining why some
 *    of them are missing.
 *
 * A category that came back unscored renders as explicitly unavailable
 * rather than as a zero gauge, because a zero and a blank mean opposite
 * things and must not look alike.
 *
 * The error text shown is the message this package wrote, not a raw
 * exception trace: these cards render behind auth in an admin context, so a
 * developer should be able to diagnose from the card without opening the
 * logs, but the card is still not a stack trace viewer.
 *
 * ### Where the card gets its authority
 *
 * **Mounting this component is the authorization decision.** It carries no
 * gate of its own, so an application that renders it on a page grants every
 * viewer of that page the ability to spend a slice of its PageSpeed API
 * quota. Put it behind whatever policy the surrounding admin area uses.
 *
 * What the component does guarantee is that a viewer cannot widen that grant
 * from the browser: `$url` and `$strategy` are `#[Locked]`, so the card can
 * only ever test and report on the address it was mounted with. Without that
 * a viewer could retarget it from the request payload and have the
 * application test — and store history for — an address of their choosing.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class ScoreCard extends Component
{
    /**
     * No API key is configured, so no run can succeed.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_NO_API_KEY = 'no-api-key';

    /**
     * No run has been stored for this URL and form factor yet.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_EMPTY = 'empty';

    /**
     * The most recent run failed.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_FAILED = 'failed';

    /**
     * The most recent run completed but lost data on the way.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_DEGRADED = 'degraded';

    /**
     * The most recent run completed cleanly.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_LOADED = 'loaded';

    /**
     * Seconds between polls while a queued run is in flight.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const POLL_SECONDS = 5;

    /**
     * Seconds to keep polling for a queued run before giving up on it.
     *
     * Set above the job's own worst case rather than above a typical run. A
     * PageSpeed run takes 20-60 seconds, but the job retries twice on a
     * transient failure with a 60 and then 300 second backoff, so a run that
     * is genuinely still coming can legitimately take several minutes.
     * Timing out below that would report a working queue as a broken one.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const RUN_TIMEOUT_SECONDS = 600;

    /**
     * Event announcing that a run this card was waiting on landed.
     *
     * The two cards read the same row but mount independently, and only this
     * one knows a run is in flight — so without an announcement the vitals
     * card sits on stale data until somebody reloads the page by hand.
     *
     * Carries the URL and form factor so that a page showing several cards
     * refreshes the ones the run actually belongs to rather than all of them.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const EVENT_RESULT_STORED = 'pagespeed-insights:result-stored';

    /**
     * The URL these scores describe.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Locked]
    public string $url = '';

    /**
     * The form factor these scores describe.
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
     * Whether a usable API key is configured.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $apiKeyConfigured = false;

    /**
     * Whether the component library the view renders with is installed.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $uiComponentsInstalled = true;

    /**
     * Whether a queued run is in flight and the card should keep polling.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $running = false;

    /**
     * The id of the result on screen, or null when there is none.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    #[Locked]
    public ?int $resultId = null;

    /**
     * The four gauges, in display order.
     *
     * @since 1.0.0
     *
     * @var array<int, array{category: string, label: string, score: int|null, band: string|null, color: string|null, bandLabel: string|null, available: bool}>
     */
    public array $gauges = [];

    /**
     * Why the last run failed, as this package wrote it.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $errorMessage = null;

    /**
     * What a degraded run lost, written for a human.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public array $warnings = [];

    /**
     * When the run on screen was fetched, already formatted.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $fetchedAt = null;

    /**
     * A problem with the card itself rather than with a run — a queue that
     * would not take the job, most often.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $actionMessage = null;

    /**
     * The heading {@see self::$actionMessage} sits under.
     *
     * Carried separately because the two things that set an action message —
     * a queue that refused the job, and a run that never came back — are not
     * the same event and must not share a heading.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $actionTitle = null;

    /**
     * When the run currently being waited on was queued, as a timestamp.
     *
     * Carried as an integer rather than as a Carbon instance because it
     * survives Livewire's round trip to the browser and back on every poll,
     * and a date object would be rehydrated from whatever the payload said
     * it was.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    #[Locked]
    public ?int $runQueuedAt = null;

    /**
     * Set the card up for one URL and form factor.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL to show scores for.
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
     * Reload the latest stored run.
     *
     * Also the poll target while a queued run is in flight: a run finishing
     * is simply a newer row appearing, so the poll and the initial load are
     * the same query and there is no separate "is it done" channel to keep
     * in step with reality.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->uiComponentsInstalled = UiComponentsInstalled::check();
        $this->apiKeyConfigured      = $this->hasApiKey();

        $result = $this->latestResult();

        if ( null !== $result && $result->getKey() !== $this->resultId ) {
            // A row this card had not seen is the only proof a queued run
            // finished, so it is what stops the polling.
            $wasWaiting = $this->running;

            $this->stopWaiting();

            // Announced only when this card was actually waiting on a run.
            // Every mount also sees a row it has not seen before, and
            // announcing those would have each card on the page refresh
            // every other one on first paint for no reason.
            if ( $wasWaiting ) {
                $this->dispatch(
                    self::EVENT_RESULT_STORED,
                    url: $this->url,
                    strategy: $this->strategy,
                );
            }
        } elseif ( $this->running && $this->waitedTooLong() ) {
            $this->giveUpWaiting();
        }

        $this->apply( $result );
    }

    /**
     * Queue a run for this URL and form factor.
     *
     * Refuses without an API key rather than queueing a job that is
     * guaranteed to fail: a keyless run costs 20-60 seconds of a worker to
     * learn something the card already knows.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function runTest(): void
    {
        $this->refresh();

        // Cleared after the refresh rather than before it: refreshing can
        // raise its own action message, and a stale "has not reported back"
        // warning sitting beside a freshly started run contradicts the
        // spinner next to it.
        $this->clearActionMessage();

        if ( '' === $this->url ) {
            $this->actionTitle   = __( 'The test could not be started' );
            $this->actionMessage = __( 'This card has no URL to test.' );

            return;
        }

        if ( ! UrlNormalizer::isValid( $this->url ) ) {
            // Caught here rather than left to the job. A URL PageSpeed cannot
            // be asked about buys a failed row and a worker held for the
            // length of the attempt, to establish something knowable now.
            $this->actionTitle   = __( 'The test could not be started' );
            $this->actionMessage = __(
                '":url" is not an address PageSpeed can test.',
                [ 'url' => $this->url ],
            );

            return;
        }

        if ( ! $this->apiKeyConfigured ) {
            return;
        }

        if ( $this->running ) {
            // A run is already in flight. Queueing a second spends another
            // slice of the API quota to answer the question the first one is
            // already answering, and the rate limiter would hold it behind
            // the first regardless.
            return;
        }

        try {
            RunPageSpeedTest::dispatch( $this->url, $this->strategy );
        } catch ( Throwable $exception ) {
            // Redacted before it reaches a browser. This message comes from
            // the queue driver rather than from this package, so it can carry
            // a connection string — and a credential in one — which the
            // card would otherwise render verbatim.
            $this->actionTitle   = __( 'The test could not be started' );
            $this->actionMessage = __(
                'The test could not be queued: :message',
                [ 'message' => PageSpeedApiException::redactCredentials( $exception->getMessage() ) ],
            );

            return;
        }

        $this->running     = true;
        $this->runQueuedAt = CarbonImmutable::now()->getTimestamp();
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
        return view( 'pagespeed-insights::livewire.score-card' );
    }

    /**
     * Stop waiting on a queued run, because it produced a row.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function stopWaiting(): void
    {
        $this->running     = false;
        $this->runQueuedAt = null;
    }

    /**
     * Stop waiting on a queued run that never came back, and say so.
     *
     * A spinner with no end is the same failure this component exists to
     * avoid, one layer up: it renders a stalled queue as work in progress,
     * so an application whose worker died presents as merely slow. Nothing
     * here claims the run failed — it may well still be queued — but the
     * card stops implying that watching it will help, and names the thing
     * worth checking.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function giveUpWaiting(): void
    {
        $this->running     = false;
        $this->runQueuedAt = null;

        $queue = $this->configuredQueueName();

        $this->actionTitle   = __( 'The test has not reported back' );
        $this->actionMessage = null === $queue
            ? __( 'The queued test has not reported back yet. It may still be waiting — check that a queue worker is running.' )
            : __(
                'The queued test has not reported back yet. It may still be waiting — check that a queue worker is processing the ":queue" queue.',
                [ 'queue' => $queue ],
            );
    }

    /**
     * Clear any standing action message.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function clearActionMessage(): void
    {
        $this->actionTitle   = null;
        $this->actionMessage = null;
    }

    /**
     * Whether the run being waited on has taken longer than this card will
     * wait.
     *
     * @since 1.0.0
     *
     * @return bool True when the wait should end.
     */
    protected function waitedTooLong(): bool
    {
        if ( null === $this->runQueuedAt ) {
            return false;
        }

        return ( CarbonImmutable::now()->getTimestamp() - $this->runQueuedAt ) >= self::RUN_TIMEOUT_SECONDS;
    }

    /**
     * The queue the package dispatches runs onto, when it names one.
     *
     * @since 1.0.0
     *
     * @return string|null The queue name, or null when the default is used.
     */
    protected function configuredQueueName(): ?string
    {
        $queue = config( 'pagespeed-insights.queue.queue' );

        return is_string( $queue ) && '' !== trim( $queue ) ? trim( $queue ) : null;
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
     * Copy a stored run onto the component, or clear it when there is none.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult|null  $result  The row to show.
     *
     * @return void
     */
    protected function apply( ?PageSpeedResult $result ): void
    {
        $this->resultId     = $result?->getKey();
        $this->errorMessage = null;
        $this->warnings     = [];
        $this->gauges       = [];
        $this->fetchedAt    = null;

        if ( ! $this->apiKeyConfigured && null === $result ) {
            // Only claim misconfiguration when there is nothing to show. An
            // install that had a key, ran tests, and then lost the key still
            // has real history worth rendering, and blanking it would hide
            // the very numbers that make the missing key worth fixing.
            $this->state = self::STATE_NO_API_KEY;

            return;
        }

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

        $this->gauges = self::buildGauges( $result );

        if ( $result->wasDegraded() ) {
            $this->state    = self::STATE_DEGRADED;
            $this->warnings = $result->warningList();

            return;
        }

        $this->state = self::STATE_LOADED;
    }

    /**
     * Whether a usable API key is configured.
     *
     * Wrapped because the repository reaches storage — the database driver
     * queries a table that an application may not have migrated yet — and a
     * card that throws on render is worse than one that reports the key as
     * missing.
     *
     * @since 1.0.0
     *
     * @return bool True when a key is available.
     */
    protected function hasApiKey(): bool
    {
        try {
            return app( ApiKeyRepository::class )->isConfigured();
        } catch ( Throwable ) {
            return false;
        }
    }

    /**
     * Build the four gauges from a completed run.
     *
     * A category the response never carried and one it carried unscored are
     * both `available: false`. They arrive by different routes — the first is
     * absent from the score set, the second is present and null — but they
     * mean the same thing to a reader, and neither is a zero.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $result  The completed run.
     *
     * @return array<int, array{category: string, label: string, score: int|null, band: string|null, color: string|null, bandLabel: string|null, available: bool}> The gauges, in display order.
     */
    protected static function buildGauges( PageSpeedResult $result ): array
    {
        $scores = $result->scores();
        $gauges = [];

        foreach ( CategoryTranslator::KNOWN as $category ) {
            $score = $scores->get( $category );
            $band  = ScoreBands::forScore( $score );

            $gauges[] = [
                'category'  => $category,
                'label'     => self::categoryLabel( $category ),
                'score'     => $score,
                'band'      => $band,
                'color'     => ScoreBands::color( $band ),
                'bandLabel' => ScoreBands::label( $band ),
                'available' => null !== $score,
            ];
        }

        return $gauges;
    }

    /**
     * A Lighthouse category's name, written for a human.
     *
     * @since 1.0.0
     *
     * @param  string  $category  The lower-kebab response key.
     *
     * @return string The translated label.
     */
    protected static function categoryLabel( string $category ): string
    {
        return match ( $category ) {
            'performance'    => __( 'Performance' ),
            'accessibility'  => __( 'Accessibility' ),
            'best-practices' => __( 'Best practices' ),
            'seo'            => __( 'SEO' ),
            default          => $category,
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
