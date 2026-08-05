<?php

/**
 * PageSpeedInsights package HTTP routes.
 *
 * The authenticated JSON endpoints behind the React and Vue components, and any
 * front end an application writes for itself. Loaded from the service provider
 * inside the configured prefix and middleware group so a host application can
 * move or disable them without editing the package.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Http\Controllers\CoreWebVitalsController;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\MonitoredUrlController;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\OpportunitiesController;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\QueueTestController;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\ResultController;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\ScoresController;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\TrendsController;
use Illuminate\Support\Facades\Route;

Route::get( 'scores', ScoresController::class )
    ->name( 'pagespeed-insights.scores' );

Route::get( 'core-web-vitals', CoreWebVitalsController::class )
    ->name( 'pagespeed-insights.core-web-vitals' );

Route::get( 'opportunities', OpportunitiesController::class )
    ->name( 'pagespeed-insights.opportunities' );

Route::get( 'trends', TrendsController::class )
    ->name( 'pagespeed-insights.trends' );

Route::get( 'urls', [ MonitoredUrlController::class, 'index' ] )
    ->name( 'pagespeed-insights.urls.index' );

Route::post( 'urls', [ MonitoredUrlController::class, 'store' ] )
    ->name( 'pagespeed-insights.urls.store' );

Route::delete( 'urls/{id}', [ MonitoredUrlController::class, 'destroy' ] )
    ->whereNumber( 'id' )
    ->name( 'pagespeed-insights.urls.destroy' );

Route::post( 'test', QueueTestController::class )
    ->name( 'pagespeed-insights.test' );

// Takes both a stored result id and an ad hoc test ticket. The pattern is
// constrained so that neither kind of id can carry a path separator into the
// route.
Route::get( 'results/{id}', ResultController::class )
    ->where( 'id', '[A-Za-z0-9-]+' )
    ->name( 'pagespeed-insights.results.show' );
