<?php

declare( strict_types=1 );

namespace Tests\Feature\Http;

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConfiguredRoutesTestCase;

/**
 * A moved prefix and a replaced middleware stack are both honoured.
 *
 * A plain PHPUnit class for the same reason as {@see RoutesDisabledTest}: both
 * settings are read when the service provider boots.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class ConfiguredRoutesTest extends ConfiguredRoutesTestCase
{
    use RefreshDatabase;

    /**
     * The endpoints answer under the configured prefix.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_serves_the_endpoints_under_the_configured_prefix(): void
    {
        PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/page' ] );

        $this->getJson( '/' . self::PREFIX . '/scores?url=https://example.com/page' )
            ->assertOk()
            ->assertJsonPath( 'url', 'https://example.com/page' );
    }

    /**
     * Moving the prefix moves them, rather than adding a second copy.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_no_longer_serves_them_under_the_default_prefix(): void
    {
        $this->getJson( '/pagespeed/scores?url=https://example.com/page' )
            ->assertNotFound();
    }

    /**
     * A middleware stack with no auth in it is honoured as written.
     *
     * Worth proving rather than assuming: an application that replaces the
     * stack is publishing these endpoints on whatever it puts there instead,
     * and the package must genuinely apply the configured stack rather than
     * quietly appending a guard of its own.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_honours_a_middleware_stack_with_no_auth_in_it(): void
    {
        PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/page' ] );

        $this->getJson( '/' . self::PREFIX . '/scores?url=https://example.com/page' )
            ->assertOk();
    }
}
