<?php

/**
 * Local test stub for the CMS framework's AdminWidgetInterface.
 *
 * The bridge under test targets this exact namespace. Autoloading the stub
 * via `autoload-dev` lets the suite exercise the wrappers and their
 * registration path without pulling the full cms-framework package — and its
 * transitive dependency tree — into `require-dev`.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\CMSFramework\Modules\AdminWidgets\Contracts;

interface AdminWidgetInterface
{
    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     capability?: string,
     *     default_options?: array<string, mixed>
     * }
     */
    public static function getWidgetInfo(): array;
}
