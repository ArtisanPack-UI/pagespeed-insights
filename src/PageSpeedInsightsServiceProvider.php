<?php

/**
 * PageSpeedInsights service provider.
 *
 * Bootstraps the PageSpeed Insights package. The scaffold registers the
 * facade accessor; the API client, configuration drivers, models, jobs,
 * routes, and UI surfaces are registered here as they are built.
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
        // Config publishing, migrations, routes, views, Livewire components,
        // and the CMS-framework bridge are registered here as each is built.
    }
}
