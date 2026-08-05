<?php

declare( strict_types=1 );

namespace Tests\Support;

use Tests\TestCase;

/**
 * A test application booted with `routes.enabled` turned off.
 *
 * The flag is read when the service provider boots, so it cannot be flipped
 * from inside a test the way most of this package's configuration can. A
 * dedicated case is the honest way to exercise it — and the flag is worth
 * exercising, because "the endpoints are not registered" is the setting an
 * API-only or headless installation relies on.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
abstract class RoutesDisabledTestCase extends TestCase
{
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
        parent::defineEnvironment( $app );

        $app[ 'config' ]->set( 'pagespeed-insights.routes.enabled', false );
    }
}
