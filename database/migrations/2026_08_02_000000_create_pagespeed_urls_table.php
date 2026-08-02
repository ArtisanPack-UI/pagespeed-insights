<?php

/**
 * Creates the pagespeed_urls table.
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
        Schema::create( 'pagespeed_urls', function ( Blueprint $table ): void {
            $table->id();

            // 500 rather than the 255 default: real URLs with campaign
            // parameters routinely run past 255 characters, and 500 utf8mb4
            // characters is 2000 bytes, inside InnoDB's 3072-byte index
            // limit so the unique index still builds on MySQL.
            $table->string( 'url', 500 )->unique();

            $table->string( 'label' )->nullable();

            // manual, sitemap, or hook — how this URL came to be monitored.
            $table->string( 'source', 20 )->default( 'manual' );

            // Which form factors to test. Defaulted in the model rather than
            // the schema because SQLite cannot default a json column.
            $table->json( 'strategies' )->nullable();

            $table->boolean( 'is_active' )->default( true );

            // Per-URL cadence override. Null means "use the package default".
            $table->string( 'test_frequency', 20 )->nullable();

            $table->timestamp( 'last_tested_at' )->nullable();

            $table->timestamps();

            // The shape of the due() scope: active rows, oldest run first.
            $table->index( [ 'is_active', 'last_tested_at' ] );
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
        Schema::dropIfExists( 'pagespeed_urls' );
    }
};
