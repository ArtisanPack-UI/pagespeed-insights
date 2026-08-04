<?php

declare( strict_types=1 );

namespace Tests;

use ArtisanPack\Accessibility\Laravel\A11yServiceProvider;
use ArtisanPack\LivewireUiComponents\LivewireUiComponentsServiceProvider;
use ArtisanPackUI\CMSFramework\Modules\AdminWidgets\Services\AdminWidgetManager;
use ArtisanPackUI\Hooks\Providers\HooksServiceProvider;
use ArtisanPackUI\Icons\IconsServiceProvider;
use ArtisanPackUI\PageSpeedInsights\PageSpeedInsightsServiceProvider;
use ArtisanPackUI\PageSpeedInsights\Support\CmsFrameworkInstalled;
use ArtisanPackUI\PageSpeedInsights\Support\LivewireInstalled;
use ArtisanPackUI\PageSpeedInsights\Support\UiComponentsInstalled;
use ArtisanPackUI\Security\SecurityServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use OwenVoke\BladeFontAwesome\BladeFontAwesomeServiceProvider;

/**
 * Base test case for the PageSpeedInsights package.
 *
 * Boots Livewire and the ArtisanPack UI component library alongside the
 * package itself. Testbench does not run Laravel's package discovery, so
 * every provider the Blade views depend on — including the two icon sets the
 * component library resolves `x-artisanpack-icon` through — has to be named
 * here or the cards fail to render with an unresolvable component tag.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Reset the memoized install checks before each test.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        LivewireInstalled::reset();
        UiComponentsInstalled::reset();
        CmsFrameworkInstalled::reset();
    }

    /**
     * Reset the memoized install checks after each test, so a test that
     * forced one does not leak the forced value into the next file.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void
    {
        LivewireInstalled::reset();
        UiComponentsInstalled::reset();
        CmsFrameworkInstalled::reset();

        parent::tearDown();
    }

    /**
     * Get the package providers to register in the test application.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     *
     * @return array<int, class-string> Array of service provider class names.
     */
    protected function getPackageProviders( $app ): array
    {
        return [
            HooksServiceProvider::class,
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeFontAwesomeServiceProvider::class,
            A11yServiceProvider::class,
            SecurityServiceProvider::class,
            IconsServiceProvider::class,
            LivewireUiComponentsServiceProvider::class,
            PageSpeedInsightsServiceProvider::class,
        ];
    }

    /**
     * Define the environment for the test application.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     *
     * @return void
     */
    protected function defineEnvironment( $app ): void
    {
        $app[ 'config' ]->set( 'app.key', 'base64:' . base64_encode( random_bytes( 32 ) ) );

        $app[ 'config' ]->set( 'database.default', 'testbench' );
        $app[ 'config' ]->set( 'database.connections.testbench', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ] );

        // Bind the CMS framework's AdminWidgetManager as a shared instance
        // before the providers boot, which is what the real cms-framework's
        // own provider does. Without the binding, every `make()` would hand
        // back a fresh manager and the widgets registered during boot would
        // be invisible to the test that went looking for them.
        $app->singleton( AdminWidgetManager::class );
    }
}
