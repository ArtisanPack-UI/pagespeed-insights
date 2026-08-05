<?php

/**
 * Stale monitored URL check command.
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

use ArtisanPackUI\PageSpeedInsights\Alerts\StalenessDetector;
use Illuminate\Console\Command;

/**
 * Reports the monitored URLs that have stopped producing results.
 *
 * The entry point for both the package's own hourly scheduled check and an
 * operator asking the question by hand — which is the case `--dry-run` is
 * for: it lists what is stale and why without sending anything and without
 * consuming the re-alert window, so asking does not silence the next real
 * alert.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class CheckStalenessCommand extends Command
{
    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'pagespeed:check-staleness
        {--dry-run : Report what is stale without alerting anybody about it.}';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Report the monitored URLs that have stopped producing PageSpeed results.';

    /**
     * Run the command.
     *
     * @since 1.0.0
     *
     * @param  StalenessDetector  $detector  Finds the URLs that went quiet.
     *
     * @return int The process exit code.
     */
    public function handle( StalenessDetector $detector ): int
    {
        $dryRun = true === $this->option( 'dry-run' );

        if ( ! $dryRun && ! $detector->enabled() ) {
            $this->components->warn( __(
                'Staleness alerting is turned off, so nothing was checked. Set pagespeed-insights.alerts.staleness.enabled to turn it on, or run this command with --dry-run to check anyway.',
            ) );

            return self::SUCCESS;
        }

        $stale = $dryRun ? $detector->detect() : $detector->handle();

        if ( [] === $stale ) {
            $this->components->info( $dryRun
                ? __( 'Every monitored URL has reported inside the window its schedule expects.' )
                : __( 'No monitored URLs need a staleness alert.' ) );

            return self::SUCCESS;
        }

        foreach ( $stale as $url ) {
            $this->components->warn( $url->describe() );
        }

        $this->components->info( __(
            ':count monitored URL(s) have stopped reporting.',
            [ 'count' => (string) count( $stale ) ],
        ) );

        return self::SUCCESS;
    }
}
