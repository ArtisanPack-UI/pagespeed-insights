<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot a full Testbench application through the package's base
| TestCase. Unit tests stay framework-free so they run without the container.
|
*/

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
