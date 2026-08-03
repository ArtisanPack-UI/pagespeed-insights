<?php

declare( strict_types=1 );

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HttpUser;
use Tests\Support\RoutesDisabledTestCase;

/**
 * `routes.enabled = false` registers nothing at all.
 *
 * A plain PHPUnit class rather than a Pest file: the flag is read when the
 * service provider boots, so it needs a base case of its own, and Pest will not
 * bind a second base case to a folder inside one it has already bound.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class RoutesDisabledTest extends RoutesDisabledTestCase
{
    use RefreshDatabase;

    /**
     * Every endpoint, as a method and a path.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: string, 1: string}> The endpoints.
     */
    public static function endpoints(): array
    {
        return [
            'scores'          => [ 'GET', '/pagespeed/scores?url=https://example.com/page' ],
            'core web vitals' => [ 'GET', '/pagespeed/core-web-vitals?url=https://example.com/page' ],
            'opportunities'   => [ 'GET', '/pagespeed/opportunities?url=https://example.com/page' ],
            'trends'          => [ 'GET', '/pagespeed/trends?url=https://example.com/page' ],
            'url index'       => [ 'GET', '/pagespeed/urls' ],
            'url store'       => [ 'POST', '/pagespeed/urls' ],
            'url destroy'     => [ 'DELETE', '/pagespeed/urls/1' ],
            'queue a test'    => [ 'POST', '/pagespeed/test' ],
            'poll a result'   => [ 'GET', '/pagespeed/results/1' ],
        ];
    }

    /**
     * An endpoint that was never registered answers 404, even to a request
     * that would otherwise have been served.
     *
     * @since 1.0.0
     *
     * @param  string  $method  The HTTP method.
     * @param  string  $path  The path.
     *
     * @return void
     */
    #[DataProvider( 'endpoints' )]
    public function test_it_registers_no_endpoints( string $method, string $path ): void
    {
        $this->actingAs( new HttpUser() )
            ->json( $method, $path )
            ->assertNotFound();
    }
}
