<?php

/**
 * Config-file driver for the PageSpeed Insights API key.
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
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/**
 * Reads the PageSpeed Insights API key from the Laravel config repository.
 *
 * This is the default driver. It is read-only: the key is managed through
 * `config/pagespeed-insights.php` and the `PAGESPEED_API_KEY` environment
 * variable.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class ConfigDriver implements ApiKeyRepository
{
    /**
     * Build the driver.
     *
     * @since 1.0.0
     *
     * @param  ConfigRepository  $config  The application config repository.
     */
    public function __construct( protected ConfigRepository $config )
    {
    }

    /**
     * Get the API key from configuration.
     *
     * @since 1.0.0
     *
     * @return string|null The configured API key, or null when unset or blank.
     */
    public function getApiKey(): ?string
    {
        $key = $this->config->get( 'pagespeed-insights.api_key' );

        if ( ! is_string( $key ) ) {
            return null;
        }

        $key = trim( $key );

        return '' === $key ? null : $key;
    }

    /**
     * Reject writes; this driver is backed by config/env files.
     *
     * @since 1.0.0
     *
     * @param  string|null  $key  Ignored.
     *
     * @throws RuntimeException Always.
     *
     * @return void
     */
    public function save( ?string $key ): void
    {
        throw new RuntimeException(
            __( 'The config driver is read-only. Set PAGESPEED_API_KEY in your environment, or switch PAGESPEED_CONFIG_DRIVER to "database" or "cms" to persist the key.' ),
        );
    }

    /**
     * Whether a usable API key is configured.
     *
     * @since 1.0.0
     *
     * @return bool True when an API key is available.
     */
    public function isConfigured(): bool
    {
        return null !== $this->getApiKey();
    }
}
