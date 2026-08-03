<?php

/**
 * Monitored-URL management Livewire component.
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
use ArtisanPackUI\PageSpeedInsights\Exceptions\SitemapException;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Support\ScoreBands;
use ArtisanPackUI\PageSpeedInsights\Support\UiComponentsInstalled;
use ArtisanPackUI\PageSpeedInsights\Urls\SitemapDiscoverer;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlNormalizer;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The monitored set, as something an operator can change.
 *
 * Every other Livewire component in this package reads one URL's history.
 * This one owns the list itself: what is monitored, what is paused, how often
 * each page is tested, and how a page joins or leaves the set.
 *
 * ### A row is not a score
 *
 * The at-a-glance column is the performance score of the most recent run for
 * each form factor the row tests, and it distinguishes four things that a
 * naive implementation renders identically as a blank cell:
 *
 * 1. **Never tested** — the URL was added and no run has landed yet.
 * 2. **The last run failed** — there is history, and the newest entry in it is
 *    a failure. Falling back to the last *successful* score here would be the
 *    worst possible reading: a page that stopped being testable a month ago
 *    would show a healthy green number indefinitely.
 * 3. **The last run returned no performance score** — it completed, but the
 *    category was absent or came back unscored. Not a zero.
 * 4. **A score**, banded the same way the score card bands it.
 *
 * Only the form factors a row actually tests get a cell, because a blank
 * desktop column on a mobile-only URL reads as a desktop run that failed.
 *
 * ### Hook-contributed rows are read-only, and that is not an oversight
 *
 * URLs registered through `ap.pageSpeed.registerUrls` are listed because an
 * operator looking at "which URLs are monitored?" has to see them — they cost
 * quota on every cycle exactly like a stored row does. But they are unsaved
 * models owned by whichever package registered them, so there is nothing to
 * pause, relabel, or delete: the next request would rebuild them from the
 * filter regardless. They carry a `hook` badge and no controls.
 *
 * Typing such a URL into the add form is still allowed, and is the documented
 * way to take one over. It creates a stored row, which then wins the merge in
 * {@see UrlRegistry} and is manageable like any other — the same thing
 * `UrlRegistry::persistHookUrls()` does, one URL at a time and on purpose.
 *
 * ### Where the manager gets its authority
 *
 * **Mounting this component is the authorization decision.** It carries no
 * gate of its own, and it can add, pause, and permanently delete monitored
 * URLs along with their score history. Put it behind whatever policy the
 * surrounding admin area uses.
 *
 * Within that grant, nothing the browser sends is trusted to name a row: every
 * action re-checks the id against {@see self::$rows}, which is `#[Locked]` and
 * built server-side, so a client cannot reach a URL the component never
 * listed. Deletion is additionally two-step, because it discards a page's
 * whole measurement history and there is no undo.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class UrlManager extends Component
{
    /**
     * The most URLs this component will list at once.
     *
     * A monitored set larger than this is unusual, and rendering one as a
     * single unbounded table is how an admin page becomes the slowest thing
     * in the application. When the cap bites the component says so rather
     * than quietly showing a prefix of the list, for the same reason the
     * trend chart says so: a truncated list that does not admit it is a list
     * that answers a question nobody asked.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_URLS = 250;

    /**
     * The longest label the `label` column holds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_LABEL_LENGTH = 255;

    /**
     * The `test_frequency` value meaning "use the package default".
     *
     * The column is nullable and a select cannot hold null, so the empty
     * string stands in for it in {@see self::$frequencies} and is translated
     * back on the way to the database.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const FREQUENCY_INHERIT = '';

    /**
     * The at-a-glance cell has no run to describe.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const MEASUREMENT_NONE = 'none';

    /**
     * The newest run for this URL and form factor failed.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const MEASUREMENT_FAILED = 'failed';

    /**
     * The newest run completed without a performance score.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const MEASUREMENT_UNAVAILABLE = 'unavailable';

    /**
     * The newest run carries a performance score.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const MEASUREMENT_SCORED = 'scored';

    /**
     * The monitored set, as rows ready to render.
     *
     * Locked: these decide which URLs the actions below will accept an id
     * for, so a client authoring its own would be naming rows the component
     * never listed.
     *
     * @since 1.0.0
     *
     * @var array<int, array{key: string, id: int|null, url: string, label: string|null, source: string, sourceLabel: string, editable: bool, isActive: bool, frequency: string, frequencyLabel: string, lastTestedAt: string|null, measurements: array<int, array{strategy: string, label: string, state: string, score: int|null, color: string|null, bandLabel: string|null}>}>
     */
    #[Locked]
    public array $rows = [];

    /**
     * The URL typed into the add form.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $newUrl = '';

    /**
     * The label typed into the add form.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $newLabel = '';

    /**
     * Each editable row's cadence override, keyed by row id.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public array $frequencies = [];

    /**
     * The row whose deletion is awaiting confirmation.
     *
     * Locked: this is what {@see self::remove()} deletes, and it is set only
     * by {@see self::confirmRemoval()}, which checks the id against the rows
     * this component listed. Leaving it writable would let a client arm the
     * confirmation against a row the operator never saw a prompt for.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    #[Locked]
    public ?int $pendingRemovalId = null;

    /**
     * The heading of the standing status message.
     *
     * The three status properties are locked together. They are the
     * component's account of what it just did, and a client that can author
     * them can make the screen report a deletion that did not happen — which
     * is the failure mode this package spends most of its design effort
     * avoiding, arriving from the browser instead of from the database.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    #[Locked]
    public ?string $statusTitle = null;

    /**
     * What the last action did, written for a human.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    #[Locked]
    public ?string $statusMessage = null;

    /**
     * The colour the status message is rendered in.
     *
     * Locked with the rest, and additionally because it reaches a class
     * attribute: an unlocked colour is a client-chosen class name on the
     * rendered alert.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Locked]
    public string $statusColor = 'info';

    /**
     * Whether the monitored set was longer than {@see self::MAX_URLS}.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $truncated = false;

    /**
     * How many URLs the monitored set holds in total.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $totalCount = 0;

    /**
     * The sitemap the import button will read.
     *
     * Locked because it is shown to the operator as a statement of what the
     * button is about to fetch; the import itself asks the discoverer rather
     * than reading this, so a client that changed it would only be lying to
     * its own screen — but a screen that lies is the thing this package
     * spends most of its design effort avoiding.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Locked]
    public string $sitemapUrl = '';

    /**
     * Whether the component library the view renders with is installed.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $uiComponentsInstalled = true;

    /**
     * Load the monitored set.
     *
     * @since 1.0.0
     *
     * @param  SitemapDiscoverer  $discoverer  Names the sitemap the import button reads.
     *
     * @return void
     */
    public function mount( SitemapDiscoverer $discoverer ): void
    {
        $this->sitemapUrl = $discoverer->defaultSitemapUrl();

        $this->refresh();
    }

    /**
     * Reload the monitored set from the registry.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->uiComponentsInstalled = UiComponentsInstalled::check();

        $monitored = $this->registry()->all();

        $this->totalCount = $monitored->count();
        $this->truncated  = $this->totalCount > self::MAX_URLS;

        /** @var Collection<int, PageSpeedUrl> $listed */
        $listed = $this->truncated ? $monitored->take( self::MAX_URLS ) : $monitored;

        $latest = self::latestRuns( $listed );

        $rows        = [];
        $frequencies = [];

        foreach ( $listed as $url ) {
            $row    = $this->buildRow( $url, $latest );
            $rows[] = $row;

            if ( $row[ 'editable' ] && null !== $row[ 'id' ] ) {
                $frequencies[ (string) $row[ 'id' ] ] = $row[ 'frequency' ];
            }
        }

        $this->rows        = $rows;
        $this->frequencies = $frequencies;

        // A row awaiting confirmation that is no longer listed — deleted in
        // another tab, or pushed past the cap — must not leave the confirm
        // prompt standing over whichever row now occupies that position.
        if ( null !== $this->pendingRemovalId && null === $this->listedRow( $this->pendingRemovalId ) ) {
            $this->pendingRemovalId = null;
        }
    }

    /**
     * Start monitoring the URL in the add form.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function add(): void
    {
        $this->resetErrorBag();
        $this->clearStatus();

        $url = trim( $this->newUrl );

        if ( '' === $url ) {
            $this->addError( 'newUrl', __( 'Enter a URL to monitor.' ) );

            return;
        }

        if ( strlen( $url ) > UrlNormalizer::MAX_LENGTH ) {
            // Worth its own message: "not an address PageSpeed can test" sends
            // somebody looking for a typo in a URL that is perfectly valid and
            // merely longer than the column.
            $this->addError( 'newUrl', __(
                'That URL is longer than :max characters, which is as much as this package stores.',
                [ 'max' => UrlNormalizer::MAX_LENGTH ],
            ) );

            return;
        }

        $normalized = UrlNormalizer::normalize( $url );

        if ( null === $normalized ) {
            $this->addError( 'newUrl', __( 'Enter a full http:// or https:// address. PageSpeed cannot test anything else.' ) );

            return;
        }

        $label = trim( $this->newLabel );

        // Counted in characters rather than bytes, because that is what the
        // varchar(255) column counts. Measuring in bytes would refuse a
        // perfectly storable label the moment it was written in a script that
        // does not fit in one byte a character.
        if ( mb_strlen( $label ) > self::MAX_LABEL_LENGTH ) {
            $this->addError( 'newLabel', __(
                'A label can be at most :max characters.',
                [ 'max' => self::MAX_LABEL_LENGTH ],
            ) );

            return;
        }

        $registry = $this->registry();

        // Checked against the canonical form rather than against what was
        // typed, so adding "https://example.com/about/" when
        // "https://example.com/about" is already monitored is refused as the
        // duplicate it is rather than accepted and silently merged.
        if ( null !== $registry->findStored( $normalized ) ) {
            $this->addError( 'newUrl', __(
                '":url" is already monitored.',
                [ 'url' => $normalized ],
            ) );

            return;
        }

        $wasHooked = null !== $this->hookedRowFor( $normalized );

        $stored = $registry->add( $normalized, '' === $label ? [] : [ 'label' => $label ] );

        if ( null === $stored ) {
            $this->addError( 'newUrl', __( 'That URL could not be stored.' ) );

            return;
        }

        $this->newUrl   = '';
        $this->newLabel = '';

        $this->setStatus(
            'success',
            __( 'URL added' ),
            $wasHooked
                ? __(
                    '":url" was registered by another package and now has a stored row you can pause, schedule, and remove.',
                    [ 'url' => $normalized ],
                )
                : __(
                    '":url" is now monitored and will be tested on the next cycle it is due.',
                    [ 'url' => $normalized ],
                ),
        );

        $this->refresh();
    }

    /**
     * Pause or resume testing for one row.
     *
     * @since 1.0.0
     *
     * @param  int  $id  The row to change.
     *
     * @return void
     */
    public function toggleActive( int $id ): void
    {
        $this->clearStatus();

        $url = $this->editableModel( $id );

        if ( null === $url ) {
            return;
        }

        $registry = $this->registry();

        if ( $url->is_active ) {
            $registry->deactivate( $url );

            $this->setStatus(
                'info',
                __( 'Testing paused' ),
                __(
                    '":url" will not be tested until you resume it. Its history is kept.',
                    [ 'url' => (string) $url->url ],
                ),
            );
        } else {
            $registry->activate( $url );

            $this->setStatus(
                'success',
                __( 'Testing resumed' ),
                __(
                    '":url" will be tested again on the next cycle it is due.',
                    [ 'url' => (string) $url->url ],
                ),
            );
        }

        $this->refresh();
    }

    /**
     * Ask for confirmation before deleting a row.
     *
     * @since 1.0.0
     *
     * @param  int  $id  The row to delete.
     *
     * @return void
     */
    public function confirmRemoval( int $id ): void
    {
        $this->clearStatus();

        if ( null === $this->listedRow( $id ) ) {
            return;
        }

        $this->pendingRemovalId = $id;
    }

    /**
     * Abandon a pending deletion.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function cancelRemoval(): void
    {
        $this->pendingRemovalId = null;
    }

    /**
     * Delete the row awaiting confirmation, and its score history with it.
     *
     * Takes no id: the row was named when the confirmation was asked for, and
     * accepting a second one here would let the button that says "yes, delete
     * this page" delete a different page.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function remove(): void
    {
        $this->clearStatus();

        $id = $this->pendingRemovalId;

        if ( null === $id ) {
            return;
        }

        $url = $this->editableModel( $id );

        $this->pendingRemovalId = null;

        if ( null === $url ) {
            return;
        }

        $address = (string) $url->url;

        $this->registry()->delete( $url );

        $this->setStatus(
            'warning',
            __( 'URL removed' ),
            __(
                '":url" is no longer monitored, and its stored results have been deleted.',
                [ 'url' => $address ],
            ),
        );

        $this->refresh();
    }

    /**
     * Add every URL the site's sitemap lists.
     *
     * Imported URLs arrive **inactive**, which is the same default the
     * `pagespeed:discover-sitemap` command has and for the same reason: a
     * 500-page sitemap activated in one click is a thousand API requests per
     * cycle against a quota nobody has looked at yet.
     *
     * The fetch happens inside the request rather than on the queue, so this
     * action is as slow as the site's own sitemap: the discoverer's caps bound
     * it at {@see SitemapDiscoverer::MAX_DOCUMENTS} documents of
     * `sitemap.timeout` seconds each, which on a deeply nested index can
     * outlast a web server's own timeout. Queueing it instead would leave the
     * operator watching a screen with nothing to say, which is the worse of
     * the two; an installation whose sitemap is that large should run
     * `pagespeed:discover-sitemap` from the console.
     *
     * @since 1.0.0
     *
     * @param  SitemapDiscoverer  $discoverer  Reads and parses the sitemap.
     *
     * @return void
     */
    public function importSitemap( SitemapDiscoverer $discoverer ): void
    {
        $this->clearStatus();

        try {
            $found = $discoverer->discover();
        } catch ( SitemapException $exception ) {
            // Shown verbatim rather than as "the import failed". This
            // component renders behind auth, the message is written by this
            // package, and "sitemap.xml returned HTTP 404" is the whole fix.
            $this->setStatus( 'error', __( 'The sitemap could not be read' ), $exception->getMessage() );

            return;
        }

        if ( [] === $found ) {
            $this->setStatus(
                'warning',
                __( 'Nothing to import' ),
                __(
                    'No URLs were found in :sitemap.',
                    [ 'sitemap' => $this->sitemapUrl ],
                ),
            );

            return;
        }

        $result = $this->registry()->import( $found, PageSpeedUrl::SOURCE_SITEMAP, false );

        $added = count( $result[ 'added' ] );

        $this->setStatus(
            0 === $added ? 'info' : 'success',
            __( 'Sitemap imported' ),
            0 === $added
                ? __(
                    'Found :found URL(s) in the sitemap; all of them were already monitored.',
                    [ 'found' => count( $found ) ],
                )
                : __(
                    'Found :found URL(s): :added added, :existing already monitored. The new URLs are paused — resume the ones you want tested.',
                    [
                        'found'    => count( $found ),
                        'added'    => $added,
                        'existing' => count( $result[ 'existing' ] ),
                    ],
                ),
        );

        $this->refresh();
    }

    /**
     * Apply a cadence override an operator picked.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The chosen cadence.
     * @param  string|null  $key  The row id the select belongs to.
     *
     * @return void
     */
    public function updatedFrequencies( mixed $value, ?string $key = null ): void
    {
        $this->clearStatus();

        $id  = null === $key || 1 !== preg_match( '/^\d+$/', $key ) ? null : (int) $key;
        $url = null === $id ? null : $this->editableModel( $id );

        if ( null === $url ) {
            // The select named a row this component did not list. Rebuilding
            // is what puts the visible selects back in step with the database.
            $this->refresh();

            return;
        }

        $frequency = is_string( $value ) ? $value : '';

        if ( self::FREQUENCY_INHERIT !== $frequency && ! array_key_exists( $frequency, PageSpeedUrl::FREQUENCY_INTERVALS ) ) {
            $this->refresh();

            return;
        }

        $this->registry()->update( $url, [
            'test_frequency' => self::FREQUENCY_INHERIT === $frequency ? null : $frequency,
        ] );

        $this->setStatus(
            'success',
            __( 'Test frequency updated' ),
            __(
                '":url" is now tested :frequency.',
                [ 'url' => (string) $url->url, 'frequency' => self::frequencyLabel( $url->frequency() ) ],
            ),
        );

        $this->refresh();
    }

    /**
     * The cadences the per-row select offers.
     *
     * @since 1.0.0
     *
     * @return array<int, array{value: string, label: string}> The options, in ascending interval order.
     */
    public function frequencyOptions(): array
    {
        $options = [
            [
                'value' => self::FREQUENCY_INHERIT,
                'label' => __(
                    'Default (:frequency)',
                    [ 'frequency' => self::frequencyLabel( PageSpeedUrl::defaultFrequency() ) ],
                ),
            ],
        ];

        foreach ( array_keys( PageSpeedUrl::FREQUENCY_INTERVALS ) as $frequency ) {
            $options[] = [
                'value' => $frequency,
                'label' => self::frequencyLabel( $frequency ),
            ];
        }

        return $options;
    }

    /**
     * Render the manager.
     *
     * @since 1.0.0
     *
     * @return View The rendered view.
     */
    public function render(): View
    {
        return view( 'pagespeed-insights::livewire.url-manager' );
    }

    /**
     * Build one table row from a monitored URL.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedUrl  $url  The monitored URL.
     * @param  array<string, PageSpeedResult>  $latest  The newest run per URL and form factor.
     *
     * @return array{key: string, id: int|null, url: string, label: string|null, source: string, sourceLabel: string, editable: bool, isActive: bool, frequency: string, frequencyLabel: string, lastTestedAt: string|null, measurements: array<int, array{strategy: string, label: string, state: string, score: int|null, color: string|null, bandLabel: string|null}>} The row.
     */
    protected function buildRow( PageSpeedUrl $url, array $latest ): array
    {
        $address = (string) $url->url;
        $id      = $url->getKey();

        // A hook contribution is an unsaved model with no key. Nothing about
        // it can be changed here: the next request rebuilds it from the
        // filter whatever this component did to it.
        $editable = is_int( $id ) && PageSpeedUrl::SOURCE_HOOK !== $url->source;

        $measurements = [];

        foreach ( $url->effectiveStrategies() as $strategy ) {
            $measurements[] = self::buildMeasurement(
                $strategy,
                $latest[ self::runKey( $address, $strategy ) ] ?? null,
            );
        }

        return [
            'key'            => is_int( $id ) ? 'stored-' . $id : 'hook-' . md5( $address ),
            'id'             => is_int( $id ) ? $id : null,
            'url'            => $address,
            'label'          => $url->label,
            'source'         => (string) $url->source,
            'sourceLabel'    => self::sourceLabel( (string) $url->source ),
            'editable'       => $editable,
            'isActive'       => (bool) $url->is_active,
            'frequency'      => null === $url->test_frequency ? self::FREQUENCY_INHERIT : (string) $url->test_frequency,
            'frequencyLabel' => self::frequencyLabel( $url->frequency() ),
            'lastTestedAt'   => $url->last_tested_at?->toDayDateTimeString(),
            'measurements'   => $measurements,
        ];
    }

    /**
     * The row for one id, when this component listed it.
     *
     * @since 1.0.0
     *
     * @param  int  $id  The id the browser named.
     *
     * @return array<string, mixed>|null The listed row, or null when there is none.
     */
    protected function listedRow( int $id ): ?array
    {
        foreach ( $this->rows as $row ) {
            if ( $row[ 'id' ] === $id ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Load the stored model behind an id the browser named.
     *
     * Every write path goes through this. The id is checked against the rows
     * this component actually rendered before it reaches the database, so a
     * client cannot pause, relabel, or delete a URL the component never
     * listed — and cannot touch a hook-contributed row at all.
     *
     * @since 1.0.0
     *
     * @param  int  $id  The id the browser named.
     *
     * @return PageSpeedUrl|null The row, or null when it is not one this component manages.
     */
    protected function editableModel( int $id ): ?PageSpeedUrl
    {
        $row = $this->listedRow( $id );

        if ( null === $row || true !== $row[ 'editable' ] ) {
            return null;
        }

        $url = PageSpeedUrl::query()->whereKey( $id )->first();

        if ( null === $url ) {
            // Deleted between render and click. Rebuilding says so by simply
            // no longer showing the row, which beats an error about an id the
            // operator never saw.
            $this->refresh();

            return null;
        }

        return $url;
    }

    /**
     * The hook contribution for one canonical URL, when there is one.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The canonical URL.
     *
     * @return PageSpeedUrl|null The hook-contributed model, or null.
     */
    protected function hookedRowFor( string $url ): ?PageSpeedUrl
    {
        return $this->registry()->hooked()->first(
            static fn ( PageSpeedUrl $candidate ): bool => (string) $candidate->url === $url,
        );
    }

    /**
     * The URL registry.
     *
     * Resolved per call rather than held, for the reason the container binds
     * it as a plain bind: the hook half of the monitored set is assembled at
     * read time.
     *
     * @since 1.0.0
     *
     * @return UrlRegistry The registry.
     */
    protected function registry(): UrlRegistry
    {
        return app( UrlRegistry::class );
    }

    /**
     * Raise a status message describing what the last action did.
     *
     * @since 1.0.0
     *
     * @param  string  $color  The daisyUI colour to render it in.
     * @param  string  $title  The heading.
     * @param  string  $message  The body.
     *
     * @return void
     */
    protected function setStatus( string $color, string $title, string $message ): void
    {
        $this->statusColor   = $color;
        $this->statusTitle   = $title;
        $this->statusMessage = $message;
    }

    /**
     * Clear the standing status message.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function clearStatus(): void
    {
        $this->statusColor   = 'info';
        $this->statusTitle   = null;
        $this->statusMessage = null;
    }

    /**
     * The newest run for each listed URL and form factor.
     *
     * Two queries whatever the size of the list, rather than one per row: the
     * ids first, then the rows behind them. Only the columns a cell is built
     * from are selected — the obvious `get()` also loads `raw_response`,
     * which runs to hundreds of kilobytes a row on an install that retains
     * payloads, for a table that reads none of it.
     *
     * @since 1.0.0
     *
     * @param  Collection<int, PageSpeedUrl>  $urls  The listed URLs.
     *
     * @return array<string, PageSpeedResult> The newest run, keyed by URL and form factor.
     */
    protected static function latestRuns( Collection $urls ): array
    {
        $addresses = $urls
            ->map( static fn ( PageSpeedUrl $url ): string => (string) $url->url )
            ->unique()
            ->values()
            ->all();

        if ( [] === $addresses ) {
            return [];
        }

        // `->get()` then a Collection pluck, rather than the query builder's
        // own pluck. That one rewrites the select list, and an aggregate
        // quietly replaced by a bare `id` beside a GROUP BY is a query that
        // errors on MySQL and returns an arbitrary row per group on SQLite —
        // which would show whichever run the engine felt like rather than the
        // newest one, and would still pass a test that only has one run.
        $ids = PageSpeedResult::query()
            ->whereIn( 'url', $addresses )
            ->groupBy( 'url', 'strategy' )
            ->selectRaw( 'MAX(id) as id' )
            ->get()
            ->pluck( 'id' )
            ->all();

        if ( [] === $ids ) {
            return [];
        }

        $runs = PageSpeedResult::query()
            ->whereIn( 'id', $ids )
            ->get( [ 'id', 'url', 'strategy', 'status', 'performance_score', 'fetched_at' ] );

        $latest = [];

        foreach ( $runs as $run ) {
            $latest[ self::runKey( (string) $run->url, (string) $run->strategy ) ] = $run;
        }

        return $latest;
    }

    /**
     * The key one URL and form factor's newest run is held under.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL.
     * @param  string  $strategy  The form factor.
     *
     * @return string The lookup key.
     */
    protected static function runKey( string $url, string $strategy ): string
    {
        return $url . '|' . $strategy;
    }

    /**
     * Describe one form factor's newest run in a single cell.
     *
     * @since 1.0.0
     *
     * @param  string  $strategy  The form factor.
     * @param  PageSpeedResult|null  $run  The newest run, or null when there is none.
     *
     * @return array{strategy: string, label: string, state: string, score: int|null, color: string|null, bandLabel: string|null} The cell.
     */
    protected static function buildMeasurement( string $strategy, ?PageSpeedResult $run ): array
    {
        $cell = [
            'strategy'  => $strategy,
            'label'     => self::strategyLabel( $strategy ),
            'state'     => self::MEASUREMENT_NONE,
            'score'     => null,
            'color'     => null,
            'bandLabel' => null,
        ];

        if ( null === $run ) {
            return $cell;
        }

        // The newest run rather than the newest *successful* one. Falling back
        // past a failure would show a healthy green number for a page that
        // stopped being testable a month ago, which is the single most
        // misleading thing this table could do.
        if ( $run->isFailed() ) {
            $cell[ 'state' ] = self::MEASUREMENT_FAILED;

            return $cell;
        }

        $score = $run->performance_score;

        if ( null === $score ) {
            $cell[ 'state' ] = self::MEASUREMENT_UNAVAILABLE;

            return $cell;
        }

        $band = ScoreBands::forScore( (int) $score );

        $cell[ 'state' ]     = self::MEASUREMENT_SCORED;
        $cell[ 'score' ]     = (int) $score;
        $cell[ 'color' ]     = ScoreBands::color( $band );
        $cell[ 'bandLabel' ] = ScoreBands::label( $band );

        return $cell;
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
        return PageSpeedRequest::STRATEGY_DESKTOP === $strategy ? __( 'Desktop' ) : __( 'Mobile' );
    }

    /**
     * A cadence's name, written for a human.
     *
     * @since 1.0.0
     *
     * @param  string  $frequency  A key of {@see PageSpeedUrl::FREQUENCY_INTERVALS}.
     *
     * @return string The translated label.
     */
    protected static function frequencyLabel( string $frequency ): string
    {
        return match ( $frequency ) {
            'hourly'  => __( 'Hourly' ),
            'daily'   => __( 'Daily' ),
            'weekly'  => __( 'Weekly' ),
            'monthly' => __( 'Monthly' ),
            default   => $frequency,
        };
    }

    /**
     * How a URL entered monitoring, written for a human.
     *
     * @since 1.0.0
     *
     * @param  string  $source  A value of {@see PageSpeedUrl::SOURCES}.
     *
     * @return string The translated label.
     */
    protected static function sourceLabel( string $source ): string
    {
        return match ( $source ) {
            PageSpeedUrl::SOURCE_SITEMAP => __( 'Sitemap' ),
            PageSpeedUrl::SOURCE_HOOK    => __( 'Hook' ),
            PageSpeedUrl::SOURCE_MANUAL  => __( 'Manual' ),
            default                      => $source,
        };
    }
}
