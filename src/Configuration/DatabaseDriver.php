<?php

/**
 * Database driver for the PageSpeed Insights API key.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Configuration;

use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores the PageSpeed Insights API key in the `pagespeed_configurations`
 * table.
 *
 * The key is encrypted with the framework Encrypter before it is written, and
 * the decrypted value is cached per-request after the first read.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class DatabaseDriver implements ApiKeyRepository
{
    /**
     * The table backing the driver.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected string $table = 'pagespeed_configurations';

    /**
     * The decrypted key, cached for the request. False means "not loaded yet",
     * which keeps a legitimately absent key (null) from being re-read on every
     * call.
     *
     * @since 1.0.0
     *
     * @var false|string|null
     */
    protected string|null|false $cache = false;

    /**
     * Build the driver.
     *
     * @since 1.0.0
     *
     * @param  ConnectionInterface  $connection  The database connection.
     * @param  Encrypter            $encrypter   The framework encrypter.
     */
    public function __construct(
        protected ConnectionInterface $connection,
        protected Encrypter $encrypter,
    ) {
    }

    /**
     * Get the stored API key.
     *
     * @since 1.0.0
     *
     * @return string|null The decrypted API key, or null when none is stored.
     */
    public function getApiKey(): ?string
    {
        if ( false !== $this->cache ) {
            return $this->cache;
        }

        try {
            $row = $this->connection->table( $this->table )->first();
        } catch ( QueryException $e ) {
            // Reading is a check callers make before doing expensive work, so
            // it degrades to "unconfigured" rather than exploding. The log
            // carries the real cause: a caller told the key is missing would
            // otherwise go hunting for PAGESPEED_API_KEY instead of running
            // the migration. Writes still throw.
            Log::warning(
                'artisanpack-ui/pagespeed-insights: could not read the ' . $this->table . ' table; treating as unconfigured. Run "php artisan migrate" to create it, or change PAGESPEED_CONFIG_DRIVER.',
                [ 'exception' => $e::class, 'message' => $e->getMessage() ],
            );

            return $this->cache = null;
        }

        if ( ! $row || empty( $row->api_key ) ) {
            return $this->cache = null;
        }

        try {
            $key = $this->encrypter->decryptString( $row->api_key );
        } catch ( Throwable $e ) {
            Log::warning(
                'artisanpack-ui/pagespeed-insights: failed to decrypt the stored PageSpeed API key; treating as unconfigured. Was APP_KEY rotated without re-encrypting the row, or the value written without encryption?',
                [ 'exception' => $e::class, 'message' => $e->getMessage() ],
            );

            return $this->cache = null;
        }

        $key = trim( $key );

        return $this->cache = ( '' === $key ? null : $key );
    }

    /**
     * Persist the API key, encrypted at rest.
     *
     * @since 1.0.0
     *
     * @param  string|null  $key  The API key to store, or null to clear it.
     *
     * @return void
     */
    public function save( ?string $key ): void
    {
        $key = null === $key ? null : trim( $key );
        $key = '' === $key ? null : $key;

        $row = [
            'api_key'    => null === $key ? null : $this->encrypter->encryptString( $key ),
            'updated_at' => now(),
        ];

        $existing = $this->connection->table( $this->table )->first();

        if ( $existing ) {
            $this->connection->table( $this->table )
                ->where( 'id', $existing->id )
                ->update( $row );
        } else {
            $row[ 'created_at' ] = now();
            $this->connection->table( $this->table )->insert( $row );
        }

        $this->flush();
    }

    /**
     * Whether a usable API key is stored.
     *
     * @since 1.0.0
     *
     * @return bool True when an API key is available.
     */
    public function isConfigured(): bool
    {
        return null !== $this->getApiKey();
    }

    /**
     * Clear the per-request cache.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function flush(): void
    {
        $this->cache = false;
    }
}
