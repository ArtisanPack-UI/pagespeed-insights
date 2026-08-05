<?php

/**
 * Creates the pagespeed_configurations table.
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
        Schema::create( 'pagespeed_configurations', function ( Blueprint $table ): void {
            $table->id();
            // Encrypted with the framework Encrypter; ciphertext is longer
            // than the raw key, so this is text rather than string.
            $table->text( 'api_key' )->nullable();
            $table->timestamps();
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
        Schema::dropIfExists( 'pagespeed_configurations' );
    }
};
