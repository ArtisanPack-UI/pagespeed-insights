<?php

/**
 * Trend chart admin widget bridge for the CMS framework.
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
use ArtisanPackUI\PageSpeedInsights\Livewire\TrendChart;
use ArtisanPackUI\PageSpeedInsights\Support\HomeUrl;
use ArtisanPackUI\PageSpeedInsights\Support\TrendSeries;

/**
 * Exposes the TrendChart Livewire component to the CMS framework's dashboard
 * as a registerable admin widget.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class TrendChartWidget extends TrendChart implements AdminWidgetInterface
{
    /**
     * Metadata used by the CMS framework's "Add Widget" panel and by
     * `AdminWidgetManager::createWidget()` to seed default options.
     *
     * The metric and range defaults are read from {@see TrendSeries} rather
     * than written out here, so a widget created tomorrow agrees with the
     * chart it is created from.
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
            'title'           => __( 'PageSpeed trend' ),
            'description'     => __( 'One measurement plotted over time, mobile against desktop.' ),
            'capability'      => 'view_pagespeed_insights',
            'default_options' => [
                'url'    => '',
                'metric' => TrendSeries::DEFAULT_METRIC,
                'range'  => TrendSeries::DEFAULT_RANGE,
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
     * @param  string|null  $url  The URL to chart; empty or null uses the home page.
     * @param  string|null  $metric  The category or lab metric to plot; null uses performance.
     * @param  int|string|null  $range  How many days back to reach; null uses 90.
     *
     * @return void
     */
    public function mount( ?string $url = null, ?string $metric = null, int|string|null $range = null ): void
    {
        parent::mount( HomeUrl::or( $url ), $metric, $range );
    }
}
