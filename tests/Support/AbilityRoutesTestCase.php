<?php

declare( strict_types=1 );

namespace Tests\Support;

use Tests\TestCase;

/**
 * A test application booted with `routes.ability` set.
 *
 * Its own case because the key is read when the service provider boots and
 * turns into route middleware there, so setting it inside a test method would
 * be setting it after the decision had already been made.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
abstract class AbilityRoutesTestCase extends TestCase
{
    /**
     * The ability this case gates the endpoints behind.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ABILITY = 'view_pagespeed_insights';

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

        $app[ 'config' ]->set( 'pagespeed-insights.routes.ability', static::ability() );
    }

    /**
     * The value to put in `routes.ability`.
     *
     * @since 1.0.0
     *
     * @return mixed The configured ability.
     */
    protected static function ability(): mixed
    {
        return self::ABILITY;
    }
}
