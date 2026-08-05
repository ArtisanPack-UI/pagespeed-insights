<?php

/**
 * Adds last_dispatched_at to the pagespeed_urls table.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migration.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table( 'pagespeed_urls', function ( Blueprint $table ): void {
            // Due-ness alone is measured from `last_tested_at`, which only a
            // completed run writes. Nothing recorded that a job had already
            // been queued, so anything keeping one on the queue longer than
            // its URL's cadence — rate-limit spreading, a quota postponement,
            // a restarted worker — made the next cycle queue it again, and a
            // multi-hour outage accumulated one duplicate per tick per URL.
            $table->timestamp( 'last_dispatched_at' )->nullable()->after( 'last_tested_at' );

            $table->index( [ 'is_active', 'last_dispatched_at' ] );
        } );
    }

    /**
     * Reverse the migration.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table( 'pagespeed_urls', function ( Blueprint $table ): void {
            $table->dropIndex( [ 'is_active', 'last_dispatched_at' ] );
            $table->dropColumn( 'last_dispatched_at' );
        } );
    }
};
