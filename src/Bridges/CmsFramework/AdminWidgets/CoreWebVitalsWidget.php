<?php

/**
 * Core Web Vitals admin widget bridge for the CMS framework.
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
use ArtisanPackUI\PageSpeedInsights\Livewire\CoreWebVitalsCard;
use ArtisanPackUI\PageSpeedInsights\Support\HomeUrl;

/**
 * Exposes the CoreWebVitalsCard Livewire component to the CMS framework's
 * dashboard as a registerable admin widget.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class CoreWebVitalsWidget extends CoreWebVitalsCard implements AdminWidgetInterface
{
    /**
     * Metadata used by the CMS framework's "Add Widget" panel and by
     * `AdminWidgetManager::createWidget()` to seed default options.
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
            'title'           => __( 'Core Web Vitals' ),
            'description'     => __( 'LCP, INP, and CLS from CrUX field data, banded against Google\'s thresholds.' ),
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
     * The URL parameter is widened to accept null for the reason given on
     * {@see ScoreCardWidget::mount()}: the widget's own seeded defaults carry
     * a null URL, and they come back through this method.
     *
     * @since 1.0.0
     *
     * @param  string|null  $url  The URL to show vitals for; empty or null uses the home page.
     * @param  string|null  $strategy  mobile or desktop; null uses mobile.
     *
     * @return void
     */
    public function mount( ?string $url = null, ?string $strategy = null ): void
    {
        parent::mount( HomeUrl::or( $url ), $strategy );
    }
}
