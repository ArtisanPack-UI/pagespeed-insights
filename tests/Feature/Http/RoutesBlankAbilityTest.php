<?php

declare( strict_types=1 );

namespace Tests\Feature\Http;

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AbilityRoutesTestCase;
use Tests\Support\HttpUser;

/**
 * A blank `routes.ability` adds nothing rather than gating on an empty name.
 *
 * `PAGESPEED_ROUTES_ABILITY=` in a .env file arrives as an empty string, and
 * `can:` with nothing after it would refuse every request against an ability
 * no application can define — a locked-out installation with no obvious cause.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class RoutesBlankAbilityTest extends AbilityRoutesTestCase
{
    use RefreshDatabase;

    /**
     * The endpoints behave exactly as they do with the key unset.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_ignores_a_blank_ability(): void
    {
        PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/page' ] );

        $this->actingAs( new HttpUser() )
            ->getJson( '/pagespeed/scores?url=https://example.com/page' )
            ->assertOk();
    }

    /**
     * Authentication is still required.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_still_requires_authentication(): void
    {
        $this->getJson( '/pagespeed/scores?url=https://example.com/page' )
            ->assertUnauthorized();
    }

    /**
     * {@inheritDoc}
     */
    protected static function ability(): mixed
    {
        return '';
    }
}
