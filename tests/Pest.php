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

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
|
| Checked-in PageSpeed responses shared by the whole suite. Nothing in this
| package calls the live API from a test.
|
*/

if ( ! function_exists( 'psiFixture' ) ) {
    /**
     * Load a checked-in PageSpeed response fixture.
     *
     * @param  string  $name  The fixture file name, without the .json extension.
     *
     * @return array<string, mixed> The decoded fixture.
     */
    function psiFixture( string $name ): array
    {
        $path = __DIR__ . '/fixtures/' . $name . '.json';

        if ( ! is_file( $path ) ) {
            throw new InvalidArgumentException( 'Unknown PageSpeed fixture: ' . $name );
        }

        return json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
    }
}
