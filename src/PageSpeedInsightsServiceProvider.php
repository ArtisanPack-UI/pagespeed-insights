<?php

/**
 * PageSpeedInsights service provider.
 *
 * Bootstraps the PageSpeed Insights package. The scaffold registers the
 * facade accessor and the API key configuration drivers; the API client,
 * models, jobs, routes, and UI surfaces are registered here as they are
 * built.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights;

use ArtisanPackUI\PageSpeedInsights\Configuration\CmsSettingsDriver;
use ArtisanPackUI\PageSpeedInsights\Configuration\ConfigDriver;
use ArtisanPackUI\PageSpeedInsights\Configuration\DatabaseDriver;
use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the PageSpeedInsights package.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class PageSpeedInsightsServiceProvider extends ServiceProvider
{
    /**
     * Register the container bindings for the package.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom( __DIR__ . '/../config/pagespeed-insights.php', 'pagespeed-insights' );

        $this->registerApiKeyDrivers();

        $this->app->singleton( 'pagespeed-insights', function (): PageSpeedInsights {
            return new PageSpeedInsights();
        } );
    }

    /**
     * Bootstrap the package.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishes( [
            __DIR__ . '/../config/pagespeed-insights.php' => config_path( 'pagespeed-insights.php' ),
        ], 'pagespeed-insights-config' );

        $this->publishes( [
            __DIR__ . '/../database/migrations' => database_path( 'migrations' ),
        ], 'pagespeed-insights-migrations' );

        $this->loadMigrationsFrom( __DIR__ . '/../database/migrations' );

        $this->registerCmsSettings();

        // Routes, views, Livewire components, and the CMS-framework
        // AdminWidget bridge are registered here as each is built.
    }

    /**
     * Bind the API key storage drivers and resolve the configured one.
     *
     * The concrete drivers are singletons so their per-request caches survive
     * repeated resolution, but the contract itself is a plain bind so that
     * `config( 'pagespeed-insights.driver' )` is re-read on every resolve —
     * which is what lets an app (or a test) switch drivers at runtime.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerApiKeyDrivers(): void
    {
        $this->app->singleton(
            ConfigDriver::class,
            fn ( Application $app ): ConfigDriver => new ConfigDriver( $app[ 'config' ] ),
        );

        $this->app->singleton(
            DatabaseDriver::class,
            fn ( Application $app ): DatabaseDriver => new DatabaseDriver( $app[ 'db' ]->connection(), $app[ 'encrypter' ] ),
        );

        $this->app->singleton(
            CmsSettingsDriver::class,
            fn ( Application $app ): CmsSettingsDriver => new CmsSettingsDriver( $app[ 'encrypter' ] ),
        );

        $this->app->bind( ApiKeyRepository::class, function ( Application $app ): ApiKeyRepository {
            $driver = $app[ 'config' ]->get( 'pagespeed-insights.driver', 'config' );

            return match ( $driver ) {
                'database' => $app->make( DatabaseDriver::class ),
                'cms'      => $app->make( CmsSettingsDriver::class ),
                default    => $app->make( ConfigDriver::class ),
            };
        } );
    }

    /**
     * Register the API key setting with the CMS framework when it is
     * installed. No-op otherwise — the package must not hard-depend on the
     * CMS framework.
     *
     * Runs inside `$this->app->booted()` because the CMS-framework helpers
     * (apRegisterSetting / apGetSetting / apUpdateSetting) are declared from
     * that package's own boot() method and Laravel's provider boot order is
     * not deterministic. Registering directly from this class's boot() would
     * silently skip the key whenever this provider happens to boot first.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCmsSettings(): void
    {
        $this->app->booted( function (): void {
            if ( ! function_exists( 'apRegisterSetting' ) ) {
                return;
            }

            $encrypter = $this->app[ 'encrypter' ];

            // The API key reaches the Settings row by two paths:
            // CmsSettingsDriver::save() and an operator using the CMS Settings
            // UI. Owning encryption in the sanitize callback makes both write
            // ciphertext, so the read side always has something to decrypt.
            $encryptKey = static function ( mixed $value ) use ( $encrypter ): ?string {
                if ( null === $value ) {
                    return null;
                }

                $trimmed = trim( (string) $value );

                if ( '' === $trimmed ) {
                    return null;
                }

                return $encrypter->encryptString( $trimmed );
            };

            apRegisterSetting( CmsSettingsDriver::KEY_API_KEY, null, $encryptKey );
        } );
    }
}
