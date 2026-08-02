<?php

/**
 * Sitemap discovery command.
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

use ArtisanPackUI\PageSpeedInsights\Exceptions\SitemapException;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Urls\SitemapDiscoverer;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;
use Illuminate\Console\Command;

/**
 * Populates the monitored set from a site's sitemap.
 *
 * Discovered URLs are stored inactive unless `--activate` is passed. That is
 * the safer default by a wide margin: a 500-page sitemap activated in one
 * command is 1,000 API requests per cycle against a quota the operator has
 * not looked at yet. Reviewing the list and turning on the pages that matter
 * is a small amount of work; discovering a burned quota is not.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class DiscoverSitemapCommand extends Command
{
    /**
     * The console command signature.
     *
     * The limit defaults to `pagespeed-insights.sitemap.limit` — 50 out of
     * the box — rather than being hardcoded here, so the cap can be raised
     * once in config instead of on every invocation.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'pagespeed:discover-sitemap
        {--sitemap= : The sitemap URL to read. Defaults to sitemap.xml at the app URL.}
        {--limit= : The most URLs to discover. Defaults to the configured cap (50).}
        {--activate : Start testing the discovered URLs immediately.}';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Discover URLs from a sitemap and add them to PageSpeed monitoring.';

    /**
     * Run the command.
     *
     * @since 1.0.0
     *
     * @param  SitemapDiscoverer  $discoverer  Reads and parses the sitemap.
     * @param  UrlRegistry  $registry  Stores what was found.
     *
     * @return int The process exit code.
     */
    public function handle( SitemapDiscoverer $discoverer, UrlRegistry $registry ): int
    {
        $sitemap = $this->stringOption( 'sitemap' ) ?? $discoverer->defaultSitemapUrl();
        $limit   = $this->limitOption();

        if ( false === $limit ) {
            $this->components->error( __( 'The --limit option must be a positive whole number.' ) );

            return self::FAILURE;
        }

        $this->components->info( __( 'Reading :sitemap', [ 'sitemap' => $sitemap ] ) );

        try {
            $found = $discoverer->discover( $sitemap, $limit );
        } catch ( SitemapException $exception ) {
            $this->components->error( $exception->getMessage() );

            return self::FAILURE;
        }

        if ( [] === $found ) {
            $this->components->warn( __( 'No URLs were found in that sitemap.' ) );

            return self::SUCCESS;
        }

        $activate = true === $this->option( 'activate' );
        $result   = $registry->import( $found, PageSpeedUrl::SOURCE_SITEMAP, $activate );

        $this->newLine();
        $this->table(
            [ __( 'URL' ), __( 'Status' ) ],
            $this->rows( $found, $result ),
        );

        $this->components->info( __(
            'Found :found URL(s): :added added, :existing already monitored.',
            [
                'found'    => (string) count( $found ),
                'added'    => (string) count( $result[ 'added' ] ),
                'existing' => (string) count( $result[ 'existing' ] ),
            ],
        ) );

        if ( [] !== $result[ 'added' ] && ! $activate ) {
            $this->components->warn( __(
                'The new URLs are inactive. Review them and activate the ones you want tested, or re-run with --activate.',
            ) );
        }

        return self::SUCCESS;
    }

    /**
     * Build one table row per discovered URL.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $found  The discovered URLs.
     * @param  array{added: array<int, PageSpeedUrl>, existing: array<int, PageSpeedUrl>, skipped: array<int, string>}  $result  What the registry did with them.
     *
     * @return array<int, array<int, string>> The table rows.
     */
    protected function rows( array $found, array $result ): array
    {
        $status = [];

        foreach ( $result[ 'added' ] as $url ) {
            $status[ (string) $url->url ] = __( 'added' );
        }

        foreach ( $result[ 'existing' ] as $url ) {
            $status[ (string) $url->url ] = __( 'already monitored' );
        }

        $rows = [];

        foreach ( $found as $url ) {
            $rows[] = [ $url, $status[ $url ] ?? __( 'skipped' ) ];
        }

        return $rows;
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

    /**
     * Read the --limit option.
     *
     * @since 1.0.0
     *
     * @return false|int|null The cap, null to use the configured one, or false when the value is not a positive integer.
     */
    protected function limitOption(): int|null|false
    {
        $value = $this->stringOption( 'limit' );

        if ( null === $value ) {
            return null;
        }

        if ( 1 !== preg_match( '/^\d+$/', $value ) || 0 === (int) $value ) {
            return false;
        }

        return (int) $value;
    }
}
