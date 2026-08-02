<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot a full Testbench application through the package's base
| TestCase. Unit tests stay framework-free so they run without the container.
|
| The CMS Settings stubs are required here so the helper functions exist
| before Testbench boots the service provider, which registers its sanitize
| callback from `$this->app->booted()`.
|
*/

require_once __DIR__ . '/Support/CmsSettingsStub.php';

pest()->extend( Tests\TestCase::class )
    ->in( 'Feature' );

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| Custom expectations shared across the suite.
|
*/

expect()->extend( 'toBeSemver', function () {
    return $this->toMatch( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/' );
} );
