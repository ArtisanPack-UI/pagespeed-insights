<?php

/**
 * Result history retention command.
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

use ArtisanPackUI\PageSpeedInsights\Support\RetentionPolicy;
use Illuminate\Console\Command;

/**
 * Applies the retention windows to stored PageSpeed history.
 *
 * Two windows, applied in one pass: results past `retention.days` are
 * deleted, and results past `retention.keep_raw_days` keep their scores but
 * lose their raw payload. The second window is the one that does the real
 * work on disk — a retained payload is hundreds of kilobytes against a score
 * row's few dozen bytes — which is why it is much shorter than the first.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class PruneCommand extends Command
{
    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'pagespeed:prune
        {--dry-run : Report what would go without deleting anything.}';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Delete expired PageSpeed results and discard raw payloads past their retention window.';

    /**
     * Run the command.
     *
     * @since 1.0.0
     *
     * @param  RetentionPolicy  $retention  The configured windows, and the work of applying them.
     *
     * @return int The process exit code.
     */
    public function handle( RetentionPolicy $retention ): int
    {
        $days        = $retention->days();
        $keepRawDays = $retention->keepRawDays();

        if ( null === $days && null === $keepRawDays ) {
            $this->components->warn( __(
                'Both retention windows are turned off, so nothing was pruned. Set pagespeed-insights.retention.days and retention.keep_raw_days to enable them.',
            ) );

            return self::SUCCESS;
        }

        $report = true === $this->option( 'dry-run' )
            ? [
                'deleted'  => $retention->expiredResults()->count(),
                'stripped' => $retention->staleRawResponses()->count(),
            ]
            : $retention->apply();

        $this->reportWindow(
            $days,
            $report[ 'deleted' ],
            'Deleted :count result(s) older than :days day(s).',
            'Would delete :count result(s) older than :days day(s).',
            'Results are never deleted: retention.days is turned off.',
        );

        $this->reportWindow(
            $keepRawDays,
            $report[ 'stripped' ],
            'Discarded the raw payload on :count result(s) older than :days day(s).',
            'Would discard the raw payload on :count result(s) older than :days day(s).',
            'Raw payloads are never discarded: retention.keep_raw_days is turned off.',
        );

        return self::SUCCESS;
    }

    /**
     * Report what one window accounted for.
     *
     * A window that is off is stated rather than left silent. The two are
     * configured separately and an operator who has just turned one off by
     * accident — a blank env var reads as off — should learn that from the
     * command that was supposed to be doing the work, not from the disk
     * usage six months later.
     *
     * @since 1.0.0
     *
     * @param  int|null  $days  The window, or null when it is turned off.
     * @param  int  $count  How many rows the window accounted for.
     * @param  string  $applied  The message for a real run.
     * @param  string  $preview  The message for a dry run.
     * @param  string  $disabled  The message for a window that is turned off.
     *
     * @return void
     */
    protected function reportWindow(
        ?int $days,
        int $count,
        string $applied,
        string $preview,
        string $disabled,
    ): void {
        if ( null === $days ) {
            $this->components->info( __( $disabled ) );

            return;
        }

        $this->components->info( __(
            true === $this->option( 'dry-run' ) ? $preview : $applied,
            [
                'count' => (string) $count,
                'days'  => (string) $days,
            ],
        ) );
    }
}
