<?php

/**
 * The set of URLs this installation monitors.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Urls;

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * The single answer to "which URLs are monitored?".
 *
 * Two sources feed it. Rows in `pagespeed_urls` are the durable set — added
 * by an operator, discovered from a sitemap, or imported through the API —
 * and carry score history. The `ap.pageSpeed.registerUrls` filter is the
 * ephemeral set: another package declaring, at boot, that its own pages
 * should be monitored without having to write to this package's table.
 *
 * Hook-contributed URLs are returned as unsaved models. That is deliberate.
 * A package that registers `/checkout` and is later uninstalled should stop
 * contributing that URL, not leave an orphaned row behind that nobody
 * remembers adding. Persisting a hook URL is an explicit act — see
 * {@see self::persistHookUrls()} — not a side effect of reading the list.
 *
 * Where a URL is contributed by both sources, the stored row wins: it is the
 * one with history, an operator-set label, and an is_active flag somebody
 * chose. Deduplication is by {@see UrlNormalizer} form, so a hook writing
 * `https://example.com/about/` does not shadow a stored
 * `https://example.com/about`.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class UrlRegistry
{
    /**
     * Filter other packages use to contribute URLs to monitor.
     *
     * Callbacks receive the list assembled so far and must return a list.
     * Each entry may be a URL string, an attribute array carrying at least a
     * `url` key, or a {@see PageSpeedUrl} instance.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const FILTER_REGISTER_URLS = 'ap.pageSpeed.registerUrls';

    /**
     * The attributes a hook entry or a CRUD call may set.
     *
     * `source` is not among them: how a URL entered monitoring is recorded
     * by this class, not chosen by the caller.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const WRITABLE_ATTRIBUTES = [ 'label', 'strategies', 'is_active', 'test_frequency' ];

    /**
     * Build the registry.
     *
     * @since 1.0.0
     *
     * @param  LoggerInterface  $logger  Where malformed hook contributions are reported.
     */
    public function __construct( protected LoggerInterface $logger )
    {
    }

    /**
     * Every monitored URL, stored and hook-contributed.
     *
     * @since 1.0.0
     *
     * @return Collection<int, PageSpeedUrl> The merged set, stored rows first.
     */
    public function all(): Collection
    {
        return $this->merge( $this->stored(), $this->hooked() );
    }

    /**
     * Every monitored URL that is still being tested.
     *
     * @since 1.0.0
     *
     * @return Collection<int, PageSpeedUrl> The merged active set.
     */
    public function active(): Collection
    {
        return $this->merge(
            $this->stored( true ),
            $this->hooked()->filter( static fn ( PageSpeedUrl $url ): bool => (bool) $url->is_active )->values(),
        );
    }

    /**
     * The rows in the database.
     *
     * @since 1.0.0
     *
     * @param  bool  $activeOnly  Whether to constrain to URLs still being tested.
     *
     * @return Collection<int, PageSpeedUrl> The stored URLs, oldest first.
     */
    public function stored( bool $activeOnly = false ): Collection
    {
        /** @var Builder<PageSpeedUrl> $query */
        $query = PageSpeedUrl::query();

        if ( $activeOnly ) {
            $query->active();
        }

        /** @var Collection<int, PageSpeedUrl> */
        return $query->orderBy( 'id' )->get();
    }

    /**
     * The URLs contributed through {@see self::FILTER_REGISTER_URLS}.
     *
     * Returned as unsaved models. Entries that are not usable — a blank
     * string, a `mailto:` URL, an array with no `url` key — are dropped and
     * logged rather than throwing, because one badly written callback in an
     * unrelated package must not take down the whole monitored set.
     *
     * @since 1.0.0
     *
     * @return Collection<int, PageSpeedUrl> The hook-contributed URLs, deduped among themselves.
     */
    public function hooked(): Collection
    {
        if ( ! function_exists( 'applyFilters' ) ) {
            return new Collection();
        }

        $contributed = applyFilters( self::FILTER_REGISTER_URLS, [] );

        if ( ! is_array( $contributed ) ) {
            $this->logger->warning(
                'A callback on the ' . self::FILTER_REGISTER_URLS . ' filter returned a non-array value. No hook URLs were registered.',
            );

            return new Collection();
        }

        $urls = new Collection();

        foreach ( $contributed as $entry ) {
            $model = $this->hookEntryToModel( $entry );

            if ( null === $model ) {
                continue;
            }

            // Two callbacks contributing the same page is normal, not an
            // error; the first spelling wins.
            if ( $urls->has( (string) $model->url ) ) {
                continue;
            }

            $urls->put( (string) $model->url, $model );
        }

        return $urls->values();
    }

    /**
     * A monitored URL by its address, stored or hook-contributed.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL in any spelling.
     *
     * @return PageSpeedUrl|null The matching URL, or null when it is not monitored.
     */
    public function find( string $url ): ?PageSpeedUrl
    {
        $normalized = UrlNormalizer::normalize( $url );

        if ( null === $normalized ) {
            return null;
        }

        return $this->all()->first(
            static fn ( PageSpeedUrl $candidate ): bool => $candidate->url === $normalized,
        );
    }

    /**
     * The stored row for a URL.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL in any spelling.
     *
     * @return PageSpeedUrl|null The stored row, or null when the URL has never been saved.
     */
    public function findStored( string $url ): ?PageSpeedUrl
    {
        $normalized = UrlNormalizer::normalize( $url );

        if ( null === $normalized ) {
            return null;
        }

        return PageSpeedUrl::query()->forUrl( $normalized )->first();
    }

    /**
     * Start monitoring a URL.
     *
     * Idempotent: adding a URL that is already stored updates the attributes
     * that were supplied and leaves the rest — and the row's history —
     * alone. That is what makes re-running sitemap discovery safe.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL to monitor.
     * @param  array<string, mixed>  $attributes  Any of {@see self::WRITABLE_ATTRIBUTES}.
     * @param  string  $source  How the URL entered monitoring.
     *
     * @return PageSpeedUrl|null The stored row, or null when the URL is not testable.
     */
    public function add( string $url, array $attributes = [], string $source = PageSpeedUrl::SOURCE_MANUAL ): ?PageSpeedUrl
    {
        $normalized = UrlNormalizer::normalize( $url );

        if ( null === $normalized ) {
            return null;
        }

        $existing = PageSpeedUrl::query()->forUrl( $normalized )->first();

        if ( null !== $existing ) {
            return $this->update( $existing, $attributes );
        }

        $model = new PageSpeedUrl();
        $model->fill( $this->writableAttributes( $attributes ) );
        $model->url    = $normalized;
        $model->source = in_array( $source, PageSpeedUrl::SOURCES, true ) ? $source : PageSpeedUrl::SOURCE_MANUAL;
        $model->save();

        return $model;
    }

    /**
     * Change a monitored URL's settings.
     *
     * The address itself is changed through `url`, which is re-normalized.
     * An address that cannot be used leaves the row's URL untouched rather
     * than corrupting it — whether it is unusable because it is not a
     * testable http(s) URL, or because another row already monitors it and
     * `pagespeed_urls.url` is unique. The second case would otherwise
     * surface as a `QueryException` out of `save()`, which is a 500 rather
     * than an answer.
     *
     * Callers that need to tell the operator *why* an address was not
     * applied should check with {@see self::findStored()} first; this method
     * never throws, which is what the rest of the class does too.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedUrl  $url  The row to change.
     * @param  array<string, mixed>  $attributes  Any of {@see self::WRITABLE_ATTRIBUTES}, plus `url`.
     *
     * @return PageSpeedUrl The saved row.
     */
    public function update( PageSpeedUrl $url, array $attributes ): PageSpeedUrl
    {
        $writable = $this->writableAttributes( $attributes );

        if ( array_key_exists( 'url', $attributes ) && is_string( $attributes[ 'url' ] ) ) {
            $normalized = UrlNormalizer::normalize( $attributes[ 'url' ] );

            if ( null !== $normalized && $this->isTakenByAnother( $normalized, $url ) ) {
                $this->logger->warning(
                    'Refused to change a monitored URL to an address another row already monitors.',
                    [ 'id' => $url->getKey(), 'from' => $url->url, 'to' => $normalized ],
                );

                $normalized = null;
            }

            if ( null !== $normalized ) {
                $writable[ 'url' ] = $normalized;
            }
        }

        if ( [] !== $writable ) {
            $url->fill( $writable );
            $url->save();
        }

        return $url;
    }

    /**
     * Stop monitoring a URL and discard its history.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedUrl  $url  The row to delete.
     *
     * @return bool True when the row was deleted.
     */
    public function delete( PageSpeedUrl $url ): bool
    {
        return true === $url->delete();
    }

    /**
     * Resume testing a URL without losing its history.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedUrl  $url  The row to reactivate.
     *
     * @return PageSpeedUrl The saved row.
     */
    public function activate( PageSpeedUrl $url ): PageSpeedUrl
    {
        return $this->update( $url, [ 'is_active' => true ] );
    }

    /**
     * Pause testing a URL, keeping the row and its history.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedUrl  $url  The row to pause.
     *
     * @return PageSpeedUrl The saved row.
     */
    public function deactivate( PageSpeedUrl $url ): PageSpeedUrl
    {
        return $this->update( $url, [ 'is_active' => false ] );
    }

    /**
     * Store a batch of URLs discovered from one source.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $urls  The URLs to store.
     * @param  string  $source  How they were found.
     * @param  bool  $active  Whether they should start being tested immediately.
     *
     * @return array{added: array<int, PageSpeedUrl>, existing: array<int, PageSpeedUrl>, skipped: array<int, string>} What happened to each URL.
     */
    public function import( array $urls, string $source = PageSpeedUrl::SOURCE_MANUAL, bool $active = false ): array
    {
        $added    = [];
        $existing = [];
        $skipped  = [];

        foreach ( $urls as $url ) {
            if ( ! is_string( $url ) ) {
                continue;
            }

            $normalized = UrlNormalizer::normalize( $url );

            if ( null === $normalized ) {
                $skipped[] = $url;

                continue;
            }

            $stored = PageSpeedUrl::query()->forUrl( $normalized )->first();

            // An existing row keeps whatever an operator decided about it:
            // re-running discovery must not silently reactivate a URL that
            // was deliberately paused, or relabel a hand-labelled page.
            if ( null !== $stored ) {
                $existing[] = $stored;

                continue;
            }

            $created = $this->add( $normalized, [ 'is_active' => $active ], $source );

            if ( null !== $created ) {
                $added[] = $created;
            }
        }

        return [
            'added'    => $added,
            'existing' => $existing,
            'skipped'  => $skipped,
        ];
    }

    /**
     * Save the hook-contributed URLs that are not stored yet.
     *
     * Available for installations that want hook URLs to gain history and
     * appear in the management UI. Nothing calls it automatically.
     *
     * @since 1.0.0
     *
     * @return array<int, PageSpeedUrl> The rows that were created.
     */
    public function persistHookUrls(): array
    {
        $created = [];

        foreach ( $this->hooked() as $url ) {
            if ( null !== PageSpeedUrl::query()->forUrl( (string) $url->url )->first() ) {
                continue;
            }

            $stored = $this->add(
                (string) $url->url,
                [
                    'label'          => $url->label,
                    'strategies'     => $url->strategies,
                    'is_active'      => $url->is_active,
                    'test_frequency' => $url->test_frequency,
                ],
                PageSpeedUrl::SOURCE_HOOK,
            );

            if ( null !== $stored ) {
                $created[] = $stored;
            }
        }

        return $created;
    }

    /**
     * Whether a different row already monitors an address.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The normalized address.
     * @param  PageSpeedUrl  $excluding  The row that is allowed to hold it.
     *
     * @return bool True when another row already has this URL.
     */
    protected function isTakenByAnother( string $url, PageSpeedUrl $excluding ): bool
    {
        $query = PageSpeedUrl::query()->forUrl( $url );

        if ( null !== $excluding->getKey() ) {
            $query->whereKeyNot( $excluding->getKey() );
        }

        return $query->exists();
    }

    /**
     * Combine the two sources, letting the stored row win a collision.
     *
     * @since 1.0.0
     *
     * @param  Collection<int, PageSpeedUrl>  $stored  The rows in the database.
     * @param  Collection<int, PageSpeedUrl>  $hooked  The hook-contributed URLs.
     *
     * @return Collection<int, PageSpeedUrl> The merged set.
     */
    protected function merge( Collection $stored, Collection $hooked ): Collection
    {
        $merged = new Collection();

        foreach ( $stored as $url ) {
            $key = UrlNormalizer::normalize( (string) $url->url ) ?? (string) $url->url;

            $merged->put( $key, $url );
        }

        foreach ( $hooked as $url ) {
            $key = (string) $url->url;

            if ( $merged->has( $key ) ) {
                continue;
            }

            $merged->put( $key, $url );
        }

        return $merged->values();
    }

    /**
     * Turn one entry from the filter into an unsaved model.
     *
     * @since 1.0.0
     *
     * @param  mixed  $entry  A URL string, an attribute array, or a model.
     *
     * @return PageSpeedUrl|null The model, or null when the entry is unusable.
     */
    protected function hookEntryToModel( mixed $entry ): ?PageSpeedUrl
    {
        $attributes = [];

        if ( $entry instanceof PageSpeedUrl ) {
            $url        = (string) $entry->url;
            $attributes = [
                'label'          => $entry->label,
                'strategies'     => $entry->strategies,
                'is_active'      => $entry->is_active,
                'test_frequency' => $entry->test_frequency,
            ];
        } elseif ( is_string( $entry ) ) {
            $url = $entry;
        } elseif ( is_array( $entry ) && isset( $entry[ 'url' ] ) && is_string( $entry[ 'url' ] ) ) {
            $url        = $entry[ 'url' ];
            $attributes = $entry;
        } else {
            $this->logger->warning(
                'A callback on the ' . self::FILTER_REGISTER_URLS . ' filter contributed an entry that is not a URL string, attribute array, or PageSpeedUrl. It was dropped.',
                [ 'type' => get_debug_type( $entry ) ],
            );

            return null;
        }

        $normalized = UrlNormalizer::normalize( $url );

        if ( null === $normalized ) {
            $this->logger->warning(
                'A callback on the ' . self::FILTER_REGISTER_URLS . ' filter contributed a URL that cannot be tested. It was dropped.',
                [ 'url' => $url ],
            );

            return null;
        }

        $model = new PageSpeedUrl();
        $model->fill( $this->writableAttributes( $attributes ) );
        $model->url    = $normalized;
        $model->source = PageSpeedUrl::SOURCE_HOOK;

        return $model;
    }

    /**
     * Keep only the attributes a caller is allowed to set.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  The supplied attributes.
     *
     * @return array<string, mixed> The permitted subset.
     */
    protected function writableAttributes( array $attributes ): array
    {
        return array_intersect_key( $attributes, array_flip( self::WRITABLE_ATTRIBUTES ) );
    }
}
