<?php

/**
 * PageSpeedInsights Facade.
 *
 * Provides static access to the PageSpeedInsights class.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * PageSpeedInsights Facade.
 *
 * @see \ArtisanPackUI\PageSpeedInsights\PageSpeedInsights
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since      1.0.0
 */
class PageSpeedInsights extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @since 1.0.0
     *
     * @return string The container binding key.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'pagespeed-insights';
    }
}
