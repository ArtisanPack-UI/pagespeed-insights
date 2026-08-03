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

use ArtisanPackUI\Google\Tokens\TokenManager;
use ArtisanPackUI\PageSpeedInsights\Alerts\AlertDispatcher;
use ArtisanPackUI\PageSpeedInsights\Alerts\RegressionDetector;
use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedClient;
use ArtisanPackUI\PageSpeedInsights\Configuration\CmsSettingsDriver;
use ArtisanPackUI\PageSpeedInsights\Configuration\ConfigDriver;
use ArtisanPackUI\PageSpeedInsights\Configuration\DatabaseDriver;
use ArtisanPackUI\PageSpeedInsights\Console\Commands\DiscoverSitemapCommand;
use ArtisanPackUI\PageSpeedInsights\Console\Commands\MonitorCommand;
use ArtisanPackUI\PageSpeedInsights\Console\Commands\PruneCommand;
use ArtisanPackUI\PageSpeedInsights\Console\Commands\TestCommand;
use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;
use ArtisanPackUI\PageSpeedInsights\Jobs\Middleware\RateLimitPageSpeedRequests;
use ArtisanPackUI\PageSpeedInsights\Livewire\CoreWebVitalsCard;
use ArtisanPackUI\PageSpeedInsights\Livewire\OpportunitiesTable;
use ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard;
use ArtisanPackUI\PageSpeedInsights\Livewire\TrendChart;
use ArtisanPackUI\PageSpeedInsights\Livewire\UrlManager;
use ArtisanPackUI\PageSpeedInsights\Scheduling\TestScheduler;
use ArtisanPackUI\PageSpeedInsights\Support\GoogleConnectionResolver;
use ArtisanPackUI\PageSpeedInsights\Support\LivewireInstalled;
use ArtisanPackUI\PageSpeedInsights\Support\RetentionPolicy;
use ArtisanPackUI\PageSpeedInsights\Urls\SitemapDiscoverer;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;
use Illuminate\Cache\RateLimiter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Throwable;

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
        $this->registerApiClient();
        $this->registerUrlServices();
        $this->registerScheduling();
        $this->registerAlerts();

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

        $this->publishes( [
            __DIR__ . '/../resources/views' => resource_path( 'views/vendor/pagespeed-insights' ),
        ], 'pagespeed-insights-views' );

        $this->loadMigrationsFrom( __DIR__ . '/../database/migrations' );
        $this->loadViewsFrom( __DIR__ . '/../resources/views', 'pagespeed-insights' );

        $this->registerCmsSettings();
        $this->registerLivewireComponents();
        $this->registerRoutes();

        if ( $this->app->runningInConsole() ) {
            $this->commands( [
                DiscoverSitemapCommand::class,
                MonitorCommand::class,
                PruneCommand::class,
                TestCommand::class,
            ] );

            $this->scheduleTasks();
        }

        // The CMS-framework AdminWidget bridge is registered here as it is
        // built.
    }

    /**
     * Load the package's HTTP routes.
     *
     * Registered inside the configured prefix and middleware group rather than
     * baked into the route file, so an application can move the endpoints,
     * put its own authorization middleware in front of them, or switch them
     * off entirely without publishing anything.
     *
     * The default middleware includes `auth` deliberately. These endpoints read
     * a site's performance history and one of them spends API quota, so an
     * installation that removes it is publishing both — which is a decision
     * worth having to make on purpose.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerRoutes(): void
    {
        if ( false === (bool) $this->app[ 'config' ]->get( 'pagespeed-insights.routes.enabled', true ) ) {
            return;
        }

        Route::group( [
            'prefix'     => (string) $this->app[ 'config' ]->get( 'pagespeed-insights.routes.prefix', 'pagespeed' ),
            'middleware' => (array) $this->app[ 'config' ]->get( 'pagespeed-insights.routes.middleware', [ 'web', 'auth' ] ),
        ], function (): void {
            $this->loadRoutesFrom( __DIR__ . '/../routes/web.php' );
        } );
    }

    /**
     * Register the package's Livewire components, when Livewire is installed.
     *
     * Livewire is a suggested dependency rather than a required one — the
     * commands, the queued job, and the alerting are all useful on an
     * application with no front end — so an install without it must boot
     * exactly as it did before these components existed rather than fail on
     * a missing base class.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerLivewireComponents(): void
    {
        if ( ! LivewireInstalled::check() ) {
            return;
        }

        Livewire::component( 'pagespeed-score-card', ScoreCard::class );
        Livewire::component( 'pagespeed-core-web-vitals', CoreWebVitalsCard::class );
        Livewire::component( 'pagespeed-opportunities', OpportunitiesTable::class );
        Livewire::component( 'pagespeed-trend-chart', TrendChart::class );
        Livewire::component( 'pagespeed-url-manager', UrlManager::class );
    }

    /**
     * Bind the queue and scheduling services.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerScheduling(): void
    {
        $this->app->bind( RateLimitPageSpeedRequests::class, fn ( Application $app ): RateLimitPageSpeedRequests => new RateLimitPageSpeedRequests(
            $app->make( RateLimiter::class ),
        ) );

        $this->app->bind( TestScheduler::class, fn ( Application $app ): TestScheduler => new TestScheduler(
            $app->make( UrlRegistry::class ),
            $app->make( ApiKeyRepository::class ),
            $app[ 'config' ],
            $app[ 'log' ],
        ) );

        $this->app->bind(
            RetentionPolicy::class,
            fn ( Application $app ): RetentionPolicy => new RetentionPolicy( $app[ 'config' ] ),
        );
    }

    /**
     * Bind the alerting services.
     *
     * Plain binds rather than singletons, for the same reason everything else
     * in this package is: the alerts block is read on each resolve, so an
     * application that changes a threshold at runtime — or a test that does —
     * gets the new value rather than the one that was live when the container
     * first built the detector.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerAlerts(): void
    {
        $this->app->bind( AlertDispatcher::class, fn ( Application $app ): AlertDispatcher => new AlertDispatcher(
            $app->make( NotificationDispatcher::class ),
            $app->make( CacheFactory::class ),
            $app[ 'config' ],
            $app[ 'log' ],
        ) );

        $this->app->bind( RegressionDetector::class, fn ( Application $app ): RegressionDetector => new RegressionDetector(
            $app->make( AlertDispatcher::class ),
            $app[ 'config' ],
            $app[ 'log' ],
        ) );
    }

    /**
     * Register the package's own scheduled tasks.
     *
     * Monitoring runs **hourly** rather than on the configured test
     * frequency, because a URL only comes due once its own interval has
     * elapsed: the tick is how often the package *looks*, not how often it
     * tests. Anything less frequent would make `hourly` — a supported
     * per-URL cadence — unreachable.
     *
     * Pruning runs **daily**, and is registered here rather than left to the
     * application because retention that has to be wired up by hand is
     * retention most installs never get. A day's worth of results is a
     * rounding error against a year-long window, so there is nothing to gain
     * from sweeping more often.
     *
     * Registered through `callAfterResolving` so that an application which
     * never resolves the scheduler does not pay for one, and turned off in
     * one config flag for applications that would rather call these commands
     * from their own schedule. The flag is read when the scheduler is
     * resolved rather than when this provider boots, so setting it after the
     * fact still takes effect.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function scheduleTasks(): void
    {
        $this->callAfterResolving( Schedule::class, function ( Schedule $schedule ): void {
            if ( false === $this->app[ 'config' ]->get( 'pagespeed-insights.scheduling.enabled', true ) ) {
                return;
            }

            // Overlapping cycles would double-queue every URL that is due,
            // and each duplicate costs quota to learn nothing.
            $schedule->command( MonitorCommand::class )
                ->hourly()
                ->withoutOverlapping();

            // The first prune of a table that has been growing for a year is
            // long enough that the next day's could start on top of it.
            $schedule->command( PruneCommand::class )
                ->daily()
                ->withoutOverlapping();
        } );
    }

    /**
     * Bind the monitored-URL services.
     *
     * The registry is a plain bind rather than a singleton because the hook
     * side of the monitored set is assembled at read time: a package that
     * registers its URLs late, or a test that adds a filter callback
     * mid-request, must be visible to the next read rather than to the next
     * process.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerUrlServices(): void
    {
        $this->app->bind(
            UrlRegistry::class,
            fn ( Application $app ): UrlRegistry => new UrlRegistry( $app[ 'log' ] ),
        );

        $this->app->bind( SitemapDiscoverer::class, fn ( Application $app ): SitemapDiscoverer => new SitemapDiscoverer(
            $app->make( HttpFactory::class ),
            $app[ 'config' ],
            $app[ 'log' ],
        ) );
    }

    /**
     * Bind the PageSpeed API client.
     *
     * Bound rather than made a singleton so that each resolve re-reads the
     * configured driver, endpoint, and logger — the same reason
     * `ApiKeyRepository` is a plain bind.
     *
     * The token manager is optional at runtime even though
     * `artisanpack-ui/google` is a required dependency: the base package can
     * be present without ever having been migrated or connected, and the
     * client's OAuth tier is a fallback, not a requirement.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerApiClient(): void
    {
        $this->app->singleton(
            GoogleConnectionResolver::class,
            fn (): GoogleConnectionResolver => new GoogleConnectionResolver(),
        );

        $this->app->bind( PageSpeedClient::class, function ( Application $app ): PageSpeedClient {
            $tokens = null;

            if ( class_exists( TokenManager::class ) ) {
                try {
                    $tokens = $app->make( TokenManager::class );
                } catch ( Throwable ) {
                    $tokens = null;
                }
            }

            return new PageSpeedClient(
                $app->make( HttpFactory::class ),
                $app[ 'config' ],
                $app->make( ApiKeyRepository::class ),
                $app->make( GoogleConnectionResolver::class ),
                $app[ 'log' ],
                $tokens,
            );
        } );
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
