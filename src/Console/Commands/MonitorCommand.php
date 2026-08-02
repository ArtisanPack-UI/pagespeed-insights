<?php

/**
 * Monitored URL testing command.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Console\Commands;

use ArtisanPackUI\PageSpeedInsights\Scheduling\TestScheduler;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;
use Illuminate\Console\Command;

/**
 * Queues PageSpeed runs for the monitored URLs that are owed one.
 *
 * The entry point for both the package's own hourly scheduled task and an
 * operator running a cycle by hand. It dispatches jobs and returns; nothing
 * here waits on Google, because a run takes 20-60 seconds and a cycle can
 * cover hundreds of them.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class MonitorCommand extends Command
{
    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'pagespeed:monitor
        {--url= : Test one monitored URL now, whether or not it is due.}';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Queue PageSpeed tests for the monitored URLs that are due.';

    /**
     * Run the command.
     *
     * @since 1.0.0
     *
     * @param  TestScheduler  $scheduler  Decides what is due and queues it.
     * @param  UrlRegistry  $registry  Resolves the --url option to a stored row.
     *
     * @return int The process exit code.
     */
    public function handle( TestScheduler $scheduler, UrlRegistry $registry ): int
    {
        // Checked here as well as inside the scheduler so the operator gets
        // the answer on their terminal rather than only in a log file.
        if ( ! $scheduler->hasCredentials() ) {
            $this->components->error( __(
                'No PageSpeed Insights API key is configured, so nothing was queued. Create a key in the Google Cloud Console with the PageSpeed Insights API enabled, then set PAGESPEED_API_KEY. There is no working keyless mode.',
            ) );

            return self::FAILURE;
        }

        $only = $this->stringOption( 'url' );

        $report = null === $only
            ? $scheduler->dispatchDue()
            : $this->dispatchOne( $scheduler, $registry, $only );

        if ( null === $report ) {
            return self::FAILURE;
        }

        if ( 0 === $report[ 'jobs' ] ) {
            $this->components->info( __( 'No monitored URLs are due for a test.' ) );

            return self::SUCCESS;
        }

        $this->components->info( __(
            'Queued :jobs test(s) across :urls URL(s).',
            [
                'jobs' => (string) $report[ 'jobs' ],
                'urls' => (string) $report[ 'urls' ],
            ],
        ) );

        return self::SUCCESS;
    }

    /**
     * Queue the runs for one named URL.
     *
     * @since 1.0.0
     *
     * @param  TestScheduler  $scheduler  Queues the runs.
     * @param  UrlRegistry  $registry  Resolves the address to a stored row.
     * @param  string  $url  The address the operator named.
     *
     * @return array{urls: int, jobs: int}|null What was queued, or null when the URL is not monitored.
     */
    protected function dispatchOne( TestScheduler $scheduler, UrlRegistry $registry, string $url ): ?array
    {
        $stored = $registry->findStored( $url );

        if ( null === $stored ) {
            $this->components->error( __(
                '":url" is not a monitored URL. Add it first, or run pagespeed:discover-sitemap to import it.',
                [ 'url' => $url ],
            ) );

            return null;
        }

        // A paused URL is still tested when it is asked for by name — the
        // operator naming it is the more specific instruction — but saying
        // so avoids the impression that testing has resumed.
        if ( ! $stored->is_active ) {
            $this->components->warn( __(
                'This URL is paused. Testing it now does not resume its schedule.',
            ) );
        }

        return $scheduler->dispatchFor( $stored );
    }

    /**
     * Read a string option, treating a blank value as absent.
     *
     * @since 1.0.0
     *
     * @param  string  $name  The option name.
     *
     * @return string|null The trimmed value, or null when it was not supplied.
     */
    protected function stringOption( string $name ): ?string
    {
        $value = $this->option( $name );

        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            return null;
        }

        return trim( $value );
    }
}
