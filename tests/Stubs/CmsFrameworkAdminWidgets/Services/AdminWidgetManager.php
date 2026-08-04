<?php

/**
 * Local test stub for the CMS framework's AdminWidgetManager.
 *
 * Mirrors the `register()` / `createWidget()` / `getAvailableWidgets()` shape
 * of the real manager — including its contract check, which is what makes a
 * wrapper that forgot to implement the interface fail here rather than in
 * production — so the suite can assert what the bridge registers without
 * pulling the full cms-framework package into `require-dev`.
 *
 * `getRegistered()` has no counterpart on the real manager; it is a window
 * onto the same array for assertions.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\CMSFramework\Modules\AdminWidgets\Services;

use ArtisanPackUI\CMSFramework\Modules\AdminWidgets\Contracts\AdminWidgetInterface;

class AdminWidgetManager
{
    /**
     * @var array<string, class-string<AdminWidgetInterface>>
     */
    protected array $widgets = [];

    /**
     * @param  class-string  $class
     */
    public function register( string $type, string $class ): void
    {
        if ( in_array( AdminWidgetInterface::class, class_implements( $class ), true ) ) {
            $this->widgets[ $type ] = $class;
        }
    }

    /**
     * @return array{
     *     type: string,
     *     component_class: class-string,
     *     title: string,
     *     capability: string|null,
     *     options: array<string, mixed>
     * }|null
     */
    public function createWidget( string $type ): ?array
    {
        if ( ! isset( $this->widgets[ $type ] ) ) {
            return null;
        }

        $class = $this->widgets[ $type ];
        $info  = $class::getWidgetInfo();

        return [
            'type'            => $type,
            'component_class' => $class,
            'title'           => $info[ 'title' ] ?? 'New Widget',
            'capability'      => $info[ 'capability' ] ?? null,
            'options'         => $info[ 'default_options' ] ?? [],
        ];
    }

    /**
     * @return array<string, array{
     *     title: string,
     *     description: string,
     *     capability?: string,
     *     default_options?: array<string, mixed>
     * }>
     */
    public function getAvailableWidgets(): array
    {
        $available = [];

        foreach ( $this->widgets as $type => $class ) {
            $available[ $type ] = $class::getWidgetInfo();
        }

        return $available;
    }

    /**
     * @return array<string, class-string<AdminWidgetInterface>>
     */
    public function getRegistered(): array
    {
        return $this->widgets;
    }
}
