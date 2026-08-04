<?php

/**
 * Score card admin widget bridge for the CMS framework.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Bridges\CmsFramework\AdminWidgets;

use ArtisanPackUI\CMSFramework\Modules\AdminWidgets\Contracts\AdminWidgetInterface;
use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard;
use ArtisanPackUI\PageSpeedInsights\Support\HomeUrl;

/**
 * Exposes the ScoreCard Livewire component to the CMS framework's dashboard
 * as a registerable admin widget.
 *
 * Extending the underlying Livewire component lets the CMS framework render
 * this class the same way as any other Livewire-backed widget while
 * satisfying the AdminWidgetInterface metadata contract.
 *
 * A widget placed from the dashboard's own picker arrives with no URL, so
 * this wrapper — and only this wrapper — falls back to the application's own
 * home page. The base component keeps its empty default, because a card
 * mounted in Blade with no URL is a mistake worth showing rather than
 * papering over.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class ScoreCardWidget extends ScoreCard implements AdminWidgetInterface
{
    /**
     * Metadata used by the CMS framework's "Add Widget" panel and by
     * `AdminWidgetManager::createWidget()` to seed default options.
     *
     * The default URL is left empty rather than resolved here: this runs
     * once when the widget is created, and baking today's `app.url` into a
     * stored options array would leave the widget pointing at the old address
     * after a domain change. Empty means "the home page, whatever it is now",
     * and {@see self::mount()} resolves it on every render.
     *
     * Empty rather than null because Livewire assigns a matching option onto
     * the component's public property before `mount()` is reached, and `$url`
     * is a non-nullable string on the base component. A null default would
     * therefore be a type error on the widget's first render — before any
     * code in this class could substitute the home page for it.
     *
     * @since 1.0.0
     *
     * @return array{
     *     title: string,
     *     description: string,
     *     capability: string,
     *     default_options: array<string, mixed>
     * } The widget information.
     */
    public static function getWidgetInfo(): array
    {
        return [
            'title'           => __( 'PageSpeed scores' ),
            'description'     => __( 'The four Lighthouse category scores from the most recent stored run.' ),
            'capability'      => 'view_pagespeed_insights',
            'default_options' => [
                'url'      => '',
                'strategy' => PageSpeedRequest::STRATEGY_MOBILE,
            ],
        ];
    }

    /**
     * Set the widget up, defaulting to the application's home page.
     *
     * The URL parameter is widened to accept null, which the base component
     * does not, so that a widget whose stored options were written by hand —
     * or by a future version of the dashboard — is answered with the home
     * page rather than with a type error. The shipped default is an empty
     * string for the reason given on {@see self::getWidgetInfo()}.
     *
     * @since 1.0.0
     *
     * @param  string|null  $url  The URL to show scores for; empty or null uses the home page.
     * @param  string|null  $strategy  mobile or desktop; null uses mobile.
     *
     * @return void
     */
    public function mount( ?string $url = null, ?string $strategy = null ): void
    {
        parent::mount( HomeUrl::or( $url ), $strategy );
    }
}
