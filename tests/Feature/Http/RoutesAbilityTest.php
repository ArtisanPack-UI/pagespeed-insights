<?php

declare( strict_types=1 );

namespace Tests\Feature\Http;

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AbilityRoutesTestCase;
use Tests\Support\HttpUser;

/**
 * `routes.ability` gates the endpoints without replacing what guards them.
 *
 * A plain PHPUnit class because the key is read when the service provider
 * boots, the same reason {@see ConfiguredRoutesTest} is one.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class RoutesAbilityTest extends AbilityRoutesTestCase
{
    use RefreshDatabase;

    /**
     * A user who fails the ability is refused everywhere.
     *
     * @since 1.0.0
     *
     * @param  string  $method  The HTTP verb.
     * @param  string  $path  The endpoint path.
     *
     * @return void
     */
    #[DataProvider( 'endpoints' )]
    public function test_it_refuses_a_user_who_fails_the_ability( string $method, string $path ): void
    {
        Gate::define( self::ABILITY, static fn (): bool => false );

        $this->actingAs( new HttpUser() )
            ->json( $method, $path )
            ->assertForbidden();
    }

    /**
     * A user who passes the ability is served as normal.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_serves_a_user_who_passes_the_ability(): void
    {
        Gate::define( self::ABILITY, static fn (): bool => true );

        PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/page' ] );

        $this->actingAs( new HttpUser() )
            ->getJson( '/pagespeed/scores?url=https://example.com/page' )
            ->assertOk()
            ->assertJsonPath( 'url', 'https://example.com/page' );
    }

    /**
     * The ability is appended to the stack rather than substituted for it.
     *
     * A guest still fails authentication rather than authorization, which is
     * what proves `auth` cannot be dropped by reaching for this key.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_keeps_authentication_in_front_of_the_ability(): void
    {
        Gate::define( self::ABILITY, static fn (): bool => true );

        $this->getJson( '/pagespeed/scores?url=https://example.com/page' )
            ->assertUnauthorized();
    }

    /**
     * Every endpoint the package registers.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: string, 1: string}> The verb and path of each endpoint.
     */
    public static function endpoints(): array
    {
        return [
            'scores'           => [ 'GET', '/pagespeed/scores?url=https://example.com/page' ],
            'core web vitals'  => [ 'GET', '/pagespeed/core-web-vitals?url=https://example.com/page' ],
            'opportunities'    => [ 'GET', '/pagespeed/opportunities?url=https://example.com/page' ],
            'trends'           => [ 'GET', '/pagespeed/trends?url=https://example.com/page' ],
            'list urls'        => [ 'GET', '/pagespeed/urls' ],
            'add a url'        => [ 'POST', '/pagespeed/urls' ],
            'remove a url'     => [ 'DELETE', '/pagespeed/urls/1' ],
            'queue a test'     => [ 'POST', '/pagespeed/test' ],
            'poll a result'    => [ 'GET', '/pagespeed/results/1' ],
        ];
    }
}
