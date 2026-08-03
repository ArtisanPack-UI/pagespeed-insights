<?php

/**
 * Presence check for the `artisanpack-ui/livewire-ui-components` package.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Support;

use ArtisanPack\LivewireUiComponents\View\Components\Card;

/**
 * A cheap, cache-friendly detector for whether the ArtisanPack UI component
 * library is installed.
 *
 * The package's Blade views are built from `x-artisanpack-*` primitives, and
 * an unresolved component tag is a fatal render error rather than a missing
 * style. So the views ask this first and render a one-line install notice
 * instead of exploding — which is the difference between a dashboard that
 * tells an operator what to install and a dashboard that 500s.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
final class UiComponentsInstalled
{
    /**
     * Cached result so repeated calls do not walk the autoloader.
     *
     * @since 1.0.0
     *
     * @var bool|null
     */
    private static ?bool $cached = null;

    /**
     * Whether the component library is loadable.
     *
     * @since 1.0.0
     *
     * @return bool True when the component library is installed.
     */
    public static function check(): bool
    {
        if ( null !== self::$cached ) {
            return self::$cached;
        }

        return self::$cached = class_exists( Card::class );
    }

    /**
     * Reset the memoized result. Only used by tests.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$cached = null;
    }

    /**
     * Force the memoized result to a specific value.
     *
     * Test-only helper for exercising the "not installed" branch without
     * literally removing the package from the autoloader.
     *
     * @since 1.0.0
     *
     * @param  bool|null  $value  The value to memoize, or null to clear it.
     *
     * @return void
     */
    public static function setForTesting( ?bool $value ): void
    {
        self::$cached = $value;
    }
}
