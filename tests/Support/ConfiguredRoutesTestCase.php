<?php

declare( strict_types=1 );

namespace Tests\Support;

use Tests\TestCase;

/**
 * A test application booted with the routes moved and the auth middleware
 * taken off.
 *
 * Both are read when the service provider boots, so both need their own case.
 * The middleware half is worth proving rather than assuming: an application
 * that replaces the stack is publishing these endpoints on whatever it puts
 * there instead, and the package must genuinely honour the setting rather than
 * quietly appending its own.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
abstract class ConfiguredRoutesTestCase extends TestCase
{
    /**
     * The prefix this case moves the endpoints to.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PREFIX = 'internal/psi';

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

        $app[ 'config' ]->set( 'pagespeed-insights.routes.prefix', self::PREFIX );
        $app[ 'config' ]->set( 'pagespeed-insights.routes.middleware', [ 'web' ] );
    }
}
