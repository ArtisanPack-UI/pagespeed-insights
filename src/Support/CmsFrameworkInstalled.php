<?php

/**
 * Presence check for the `artisanpack-ui/cms-framework` package.
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

/**
 * A cheap, cache-friendly detector for whether the CMS framework is
 * installed.
 *
 * The CMS framework is a suggested dependency, not a required one, so the
 * AdminWidget bridge is gated behind this check: an install without it must
 * boot exactly as it did before the bridge existed rather than fatal on a
 * missing interface. The widget wrapper classes name CMS-framework types in
 * their `implements` clause, so they cannot even be referenced until this
 * returns true.
 *
 * Memoized because the answer cannot change inside a process and the check
 * otherwise walks the autoloader on every provider boot.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
final class CmsFrameworkInstalled
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
     * Whether the CMS framework's AdminWidget entry points are both
     * loadable.
     *
     * Both halves are checked rather than just one: the wrappers implement
     * the contract and the provider hands them to the manager, so a partial
     * install — the interface present without the manager, which is what a
     * half-finished upgrade looks like — must read as absent rather than as
     * present and then fail at registration time.
     *
     * @since 1.0.0
     *
     * @return bool True when the CMS framework's AdminWidget module is installed.
     */
    public static function check(): bool
    {
        if ( null !== self::$cached ) {
            return self::$cached;
        }

        return self::$cached = interface_exists( \ArtisanPackUI\CMSFramework\Modules\AdminWidgets\Contracts\AdminWidgetInterface::class )
            && class_exists( \ArtisanPackUI\CMSFramework\Modules\AdminWidgets\Services\AdminWidgetManager::class );
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
