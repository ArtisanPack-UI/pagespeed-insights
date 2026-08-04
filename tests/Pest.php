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

// Note: the route prefix, middleware, and enabled flag are all read when the
// service provider boots, so they cannot be flipped from inside a test the way
// the rest of this package's configuration can. The two cases that cover them
// are plain PHPUnit classes rather than Pest files, because binding a second
// base case to a folder inside the one below is not something Pest allows.
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

if ( ! function_exists( 'psiFailingNotifications' ) ) {
    /**
     * Make every notification delivery throw, as a dead mailer would.
     *
     * `Notification::fake()` cannot express this — it records deliveries
     * rather than attempting them — so the contract the alert dispatcher
     * depends on is rebound instead.
     *
     * @return void
     */
    function psiFailingNotifications(): void
    {
        app()->bind(
            Illuminate\Contracts\Notifications\Dispatcher::class,
            function (): Illuminate\Contracts\Notifications\Dispatcher {
                $message    = 'Connection could not be established with host smtp.example.com.';
                $dispatcher = Mockery::mock( Illuminate\Contracts\Notifications\Dispatcher::class );

                $dispatcher->shouldReceive( 'send' )->andThrow( new RuntimeException( $message ) );
                $dispatcher->shouldReceive( 'sendNow' )->andThrow( new RuntimeException( $message ) );

                return $dispatcher;
            },
        );
    }
}

if ( ! function_exists( 'psiWorkingNotifications' ) ) {
    /**
     * Undo {@see psiFailingNotifications()}, as a mailer coming back would.
     *
     * The rebinding above wins over the framework's own alias, so
     * `Notification::fake()` on its own does not reach the alert dispatcher
     * once a test has made deliveries throw. Pointing the contract back at
     * whatever the facade currently resolves to does, fake or not.
     *
     * @return void
     */
    function psiWorkingNotifications(): void
    {
        app()->bind(
            Illuminate\Contracts\Notifications\Dispatcher::class,
            static fn (): Illuminate\Contracts\Notifications\Dispatcher =>
                Illuminate\Support\Facades\Notification::getFacadeRoot(),
        );
    }
}

if ( ! function_exists( 'psiSitemapUrlset' ) ) {
    /**
     * Build a sitemap `urlset` document listing the given page URLs.
     *
     * Shared rather than declared per test file: Pest loads every test file
     * into one process, so the same helper declared in two of them is a
     * redeclaration fatal, and a generic name like `urlset` is easy to
     * collide with by accident.
     *
     * @param  array<int, string>  $urls  The locations to list.
     *
     * @return string The XML.
     */
    function psiSitemapUrlset( array $urls ): string
    {
        $entries = implode( '', array_map(
            static fn ( string $url ): string => '<url><loc>' . $url . '</loc></url>',
            $urls,
        ) );

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $entries . '</urlset>';
    }
}

if ( ! function_exists( 'psiSitemapIndex' ) ) {
    /**
     * Build a `sitemapindex` document naming the given child sitemaps.
     *
     * @param  array<int, string>  $sitemaps  The child sitemap URLs.
     *
     * @return string The XML.
     */
    function psiSitemapIndex( array $sitemaps ): string
    {
        $entries = implode( '', array_map(
            static fn ( string $url ): string => '<sitemap><loc>' . $url . '</loc></sitemap>',
            $sitemaps,
        ) );

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $entries . '</sitemapindex>';
    }
}

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
