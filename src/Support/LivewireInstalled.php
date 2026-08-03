<?php

/**
 * Presence check for the `livewire/livewire` package.
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

use Livewire\Component;
use Livewire\Livewire;

/**
 * A cheap, cache-friendly detector for whether Livewire is installed.
 *
 * Livewire is a suggested dependency, not a required one: the package's
 * commands, jobs, and alerting are useful on an application that has no
 * front end at all. So the components are registered behind this check
 * rather than unconditionally, and an install without Livewire boots
 * exactly as it did before they existed.
 *
 * Memoized because the answer cannot change inside a process and the
 * check otherwise walks the autoloader on every provider boot.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
final class LivewireInstalled
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
     * Whether Livewire's public entry points are loadable.
     *
     * @since 1.0.0
     *
     * @return bool True when Livewire is installed.
     */
    public static function check(): bool
    {
        if ( null !== self::$cached ) {
            return self::$cached;
        }

        return self::$cached = class_exists( Livewire::class ) && class_exists( Component::class );
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
