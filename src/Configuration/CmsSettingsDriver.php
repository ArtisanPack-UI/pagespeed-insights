<?php

/**
 * CMS Framework Settings driver for the PageSpeed Insights API key.
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
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Stores the PageSpeed Insights API key in the CMS framework's Settings
 * module.
 *
 * Only usable when `artisanpack-ui/cms-framework` is installed. Reads and
 * writes go through `apGetSetting()` / `apUpdateSetting()`, with a matching
 * `apRegisterSetting()` at boot time, so the key lives alongside every other
 * site-level setting the CMS manages.
 *
 * Encryption is owned by the sanitize callback registered in the service
 * provider rather than by `save()` here, so that an operator typing the key
 * into the CMS Settings UI writes the same ciphertext shape this driver reads
 * back.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class CmsSettingsDriver implements ApiKeyRepository
{
    /**
     * The CMS setting key holding the encrypted API key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY_API_KEY = 'artisanpack_pagespeed_api_key';

    /**
     * The decrypted key, cached for the request. False means "not loaded yet".
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
     * @param  Encrypter  $encrypter  The framework encrypter.
     */
    public function __construct( protected Encrypter $encrypter )
    {
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

        if ( ! function_exists( 'apGetSetting' ) ) {
            Log::warning(
                'artisanpack-ui/pagespeed-insights: the "cms" API key driver is selected but artisanpack-ui/cms-framework is not installed; treating as unconfigured. Install the CMS framework or change PAGESPEED_CONFIG_DRIVER.',
            );

            return $this->cache = null;
        }

        $cipher = apGetSetting( self::KEY_API_KEY );

        if ( empty( $cipher ) || ! is_string( $cipher ) ) {
            return $this->cache = null;
        }

        try {
            $key = $this->encrypter->decryptString( $cipher );
        } catch ( Throwable $e ) {
            Log::warning(
                'artisanpack-ui/pagespeed-insights: failed to decrypt the CMS-stored PageSpeed API key; treating as unconfigured. Was APP_KEY rotated without re-encrypting the setting, or the setting written before its sanitize callback was registered?',
                [ 'exception' => $e::class, 'message' => $e->getMessage() ],
            );

            return $this->cache = null;
        }

        $key = trim( $key );

        return $this->cache = ( '' === $key ? null : $key );
    }

    /**
     * Persist the API key through the CMS Settings module.
     *
     * The plaintext value is handed to `apUpdateSetting()`; the sanitize
     * callback registered by the service provider encrypts it before storage.
     *
     * @since 1.0.0
     *
     * @param  string|null  $key  The API key to store, or null to clear it.
     *
     * @throws RuntimeException When the CMS framework is not installed.
     *
     * @return void
     */
    public function save( ?string $key ): void
    {
        if ( ! function_exists( 'apUpdateSetting' ) ) {
            throw new RuntimeException(
                __( 'The cms driver requires artisanpack-ui/cms-framework to be installed. Install it, or change PAGESPEED_CONFIG_DRIVER to "config" or "database".' ),
            );
        }

        apUpdateSetting( self::KEY_API_KEY, $key );

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
