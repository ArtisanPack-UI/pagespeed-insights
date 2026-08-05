<?php

/**
 * Scheduled PageSpeed test dispatcher.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Scheduling;

use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;
use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides which monitored URLs are owed a run, and queues them.
 *
 * ### The preflight
 *
 * A cycle short-circuits entirely when no API key is configured, with a
 * single log line, rather than fanning out N URLs × 2 form factors of jobs
 * that each fail for the same reason. One loud error beats a hundred quiet
 * ones, and every one of those jobs would cost a queue slot to learn
 * something the scheduler already knew before it started.
 *
 * ### Hook-contributed URLs
 *
 * URLs contributed through `ap.pageSpeed.registerUrls` are persisted before a
 * cycle rather than dispatched as they are. They have to be: an unsaved model
 * has nowhere to record `last_tested_at`, so it would read as due on every
 * single cycle whatever its cadence says, and its results would have no
 * parent row to hang history from. This is the one place in the package that
 * calls {@see UrlRegistry::persistHookUrls()} automatically, and it can be
 * turned off with `scheduling.persist_hook_urls`.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class TestScheduler
{
    /**
     * Build the scheduler.
     *
     * @since 1.0.0
     *
     * @param  UrlRegistry  $registry  The monitored set.
     * @param  ApiKeyRepository  $apiKeys  The configured API key storage driver.
     * @param  ConfigRepository  $config  The application config repository.
     * @param  LoggerInterface  $logger  Where cycle diagnostics go.
     */
    public function __construct(
        protected UrlRegistry $registry,
        protected ApiKeyRepository $apiKeys,
        protected ConfigRepository $config,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Whether a cycle could run at all.
     *
     * A driver that cannot read its own storage — an unmigrated database,
     * say — reports as unconfigured rather than throwing, so the caller gets
     * the actionable "configure a key" answer instead of a query exception.
     *
     * @since 1.0.0
     *
     * @return bool True when an API key is available.
     */
    public function hasCredentials(): bool
    {
        try {
            return $this->apiKeys->isConfigured();
        } catch ( Throwable $exception ) {
            $this->logger->error(
                'The PageSpeed API key driver could not read its storage, so no tests were dispatched.',
                [ 'driver' => $this->apiKeys::class, 'error' => $exception->getMessage() ],
            );

            return false;
        }
    }

    /**
     * Queue a run for every monitored URL that is owed one.
     *
     * @since 1.0.0
     *
     * @param  CarbonInterface|null  $now  The moment to measure due-ness from; defaults to now.
     *
     * @return array{urls: int, jobs: int} How many URLs were due and how many jobs that came to.
     */
    public function dispatchDue( ?CarbonInterface $now = null ): array
    {
        if ( ! $this->preflight() ) {
            return [ 'urls' => 0, 'jobs' => 0 ];
        }

        $this->persistHookUrls();

        /** @var Builder<PageSpeedUrl> $query */
        $query = PageSpeedUrl::query();

        // `notInFlight` is what stops a tick re-queuing URLs whose jobs from
        // an earlier tick are still on the queue. Due-ness alone is measured
        // from `last_tested_at`, which only a completed run writes, so during
        // a quota outage every tick would otherwise re-dispatch the whole due
        // set and each duplicate would spend real quota to learn nothing.
        /** @var Collection<int, PageSpeedUrl> $due */
        $due = $query->due( $now )->notInFlight( $now )->orderBy( 'id' )->get();

        return $this->dispatchAll( $due );
    }

    /**
     * Queue a run for one monitored URL, whether or not it is due.
     *
     * The manual entry point. Due-ness is deliberately not consulted: an
     * operator asking for a specific URL to be tested has already decided
     * that it should be.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedUrl  $url  The row to test.
     *
     * @return array{urls: int, jobs: int} How many URLs were dispatched and how many jobs that came to.
     */
    public function dispatchFor( PageSpeedUrl $url ): array
    {
        if ( ! $this->preflight() ) {
            return [ 'urls' => 0, 'jobs' => 0 ];
        }

        return $this->dispatchAll( new Collection( [ $url ] ) );
    }

    /**
     * Queue one job per URL per configured form factor.
     *
     * @since 1.0.0
     *
     * @param  Collection<int, PageSpeedUrl>  $urls  The URLs to test.
     *
     * @return array{urls: int, jobs: int} How many URLs were dispatched and how many jobs that came to.
     */
    protected function dispatchAll( Collection $urls ): array
    {
        $jobs = 0;

        foreach ( $urls as $url ) {
            $dispatched = 0;

            foreach ( $url->effectiveStrategies() as $strategy ) {
                RunPageSpeedTest::dispatch(
                    (string) $url->url,
                    $strategy,
                    $url->exists ? $url->getKey() : null,
                );

                ++$dispatched;
            }

            // Stamped after the jobs are on the queue rather than before, so a
            // dispatch that threw does not leave the URL looking busy for the
            // length of a retry window. A hook-contributed row has nothing to
            // stamp; it is rebuilt from the filter on the next tick anyway.
            if ( $dispatched > 0 && $url->exists ) {
                $url->forceFill( [ 'last_dispatched_at' => CarbonImmutable::now() ] )->save();
            }

            $jobs += $dispatched;
        }

        $report = [ 'urls' => $urls->count(), 'jobs' => $jobs ];

        if ( $jobs > 0 ) {
            $this->logger->info( 'Queued PageSpeed tests.', $report );
        }

        return $report;
    }

    /**
     * Refuse a cycle that cannot possibly succeed, saying why once.
     *
     * @since 1.0.0
     *
     * @return bool True when the cycle may proceed.
     */
    protected function preflight(): bool
    {
        if ( $this->hasCredentials() ) {
            return true;
        }

        $this->logger->error(
            'No PageSpeed Insights API key is configured, so no tests were dispatched. Create a key in the Google Cloud Console with the PageSpeed Insights API enabled and set PAGESPEED_API_KEY, or store one through the "database" or "cms" driver. PageSpeed has no working keyless mode: Google\'s shared anonymous project has a daily quota of zero.',
        );

        return false;
    }

    /**
     * Save the hook-contributed URLs so they can carry a cadence and history.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function persistHookUrls(): void
    {
        if ( false === $this->config->get( 'pagespeed-insights.scheduling.persist_hook_urls', true ) ) {
            return;
        }

        $created = $this->registry->persistHookUrls();

        if ( [] === $created ) {
            return;
        }

        $this->logger->info(
            'Stored URLs contributed through the ' . UrlRegistry::FILTER_REGISTER_URLS . ' filter so they can be tested on a cadence.',
            [ 'urls' => array_map( static fn ( PageSpeedUrl $url ): string => (string) $url->url, $created ) ],
        );
    }
}
