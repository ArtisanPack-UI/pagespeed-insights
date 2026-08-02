<?php

/**
 * Google connection resolver for the OAuth fallback.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Support;

use ArtisanPackUI\Google\Models\GoogleConnection;
use Throwable;

/**
 * Finds a connected Google account for the API client's OAuth fallback.
 *
 * PageSpeed runs happen on a queue, with no authenticated user in scope, so
 * this deliberately does not filter by user the way the Search Console
 * resolver does — it takes the most recently updated connected account.
 * Applications that need tenant-scoped behavior can rebind this class in the
 * container.
 *
 * Everything is guarded: the base package may be installed but never
 * migrated, in which case querying the table throws. A missing connection is
 * an expected state, not an error, so failures here return null and let the
 * client fall through to its "no credentials" path.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class GoogleConnectionResolver
{
    /**
     * The connection to borrow an access token from, if there is one.
     *
     * @since 1.0.0
     *
     * @return GoogleConnection|null The connection, or null when none is usable.
     */
    public function resolve(): ?GoogleConnection
    {
        if ( ! class_exists( GoogleConnection::class ) ) {
            return null;
        }

        try {
            return GoogleConnection::query()
                ->where( 'status', GoogleConnection::STATUS_CONNECTED )
                ->orderByDesc( 'updated_at' )
                ->first();
        } catch ( Throwable ) {
            return null;
        }
    }
}
