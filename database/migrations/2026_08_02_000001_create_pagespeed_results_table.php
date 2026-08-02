<?php

/**
 * Creates the pagespeed_results table.
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
        Schema::create( 'pagespeed_results', function ( Blueprint $table ): void {
            $table->id();

            // Null for an ad hoc run of a URL that is not being monitored.
            // Nulled rather than cascaded on delete so that un-monitoring a
            // URL does not silently destroy its score history.
            $table->foreignId( 'pagespeed_url_id' )
                ->nullable()
                ->constrained( 'pagespeed_urls' )
                ->nullOnDelete();

            // Denormalized so history survives the monitored row being
            // deleted, and so ad hoc runs have somewhere to record what was
            // tested.
            $table->string( 'url', 500 );

            // The URL Lighthouse ended up on after redirects. Not the same
            // as `url` whenever a redirect is in play, and the DTO carries
            // it, so storing it keeps the mapping lossless.
            //
            // Unbounded text rather than a bounded string because this value
            // comes back from Google rather than from us, and it is not
            // indexed so nothing forces a limit. A redirect target past the
            // limit would otherwise throw on MySQL in strict mode and lose
            // the whole result row over a query string.
            $table->text( 'final_url' )->nullable();

            $table->string( 'strategy', 20 );

            // Lighthouse reports each category score as a 0-1 float or null;
            // these hold the null-safe round( score * 100 ). Null means the
            // category was absent, or present and unscored — `warnings` says
            // which.
            $table->unsignedTinyInteger( 'performance_score' )->nullable();
            $table->unsignedTinyInteger( 'accessibility_score' )->nullable();
            $table->unsignedTinyInteger( 'best_practices_score' )->nullable();
            $table->unsignedTinyInteger( 'seo_score' )->nullable();

            $table->json( 'lab_metrics' )->nullable();
            $table->json( 'field_data' )->nullable();
            $table->json( 'origin_field_data' )->nullable();
            $table->json( 'opportunities' )->nullable();

            // Everything the parser tolerated, plus Lighthouse's own
            // runWarnings. Without it a run that quietly lost three of four
            // scores is indistinguishable, six months later, from a page
            // that genuinely only scored on one.
            $table->json( 'warnings' )->nullable();

            $table->string( 'lighthouse_version', 32 )->nullable();

            // Only populated when pagespeed-insights.store_raw_response is
            // true. A single response runs to hundreds of kilobytes, which
            // is past what MySQL's TEXT holds, hence longText.
            $table->longText( 'raw_response' )->nullable();

            // completed or failed.
            $table->string( 'status', 20 )->default( 'completed' );

            $table->text( 'error_message' )->nullable();

            $table->timestamp( 'fetched_at' )->nullable();

            $table->timestamps();

            // The trend query: one URL, one form factor, in time order.
            // 500 + 20 utf8mb4 characters plus the timestamp comes to 2084
            // bytes, inside InnoDB's 3072-byte index limit, so this builds
            // on MySQL without a prefix. Named explicitly because Laravel's
            // generated name for three columns exceeds MySQL's 64-character
            // identifier limit.
            $table->index( [ 'url', 'strategy', 'created_at' ], 'pagespeed_results_url_strategy_created_index' );
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
        Schema::dropIfExists( 'pagespeed_results' );
    }
};
