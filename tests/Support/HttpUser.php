<?php

declare( strict_types=1 );

namespace Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A minimal authenticated user for the HTTP endpoint tests.
 *
 * The package has no user model of its own and does not want one: the
 * endpoints only ever ask whether a request is authenticated, never who it is
 * authenticated as. A synthetic Authenticatable is enough to exercise that and
 * keeps the suite from depending on a host application's schema.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class HttpUser implements Authenticatable
{
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return 1;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken( $value ): void
    {
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
