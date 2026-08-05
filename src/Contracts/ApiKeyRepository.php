<?php

/**
 * API key repository contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Contracts;

/**
 * Contract for PageSpeed Insights API key storage drivers.
 *
 * The PageSpeed Insights API requires an API key. Keyless requests are not a
 * degraded-but-working mode: a live probe returned HTTP 429 with a
 * `defaultPerDayPerProject` quota limit of zero on Google's shared anonymous
 * project. Callers should therefore treat `isConfigured() === false` as a hard
 * blocker and short-circuit before spending 20-60 seconds on a run that is
 * guaranteed to fail.
 *
 * Implementations back either config/env files, a database table, or the CMS
 * framework's Settings module.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
interface ApiKeyRepository
{
    /**
     * Get the stored PageSpeed Insights API key.
     *
     * @since 1.0.0
     *
     * @return string|null The API key, or null when none is stored.
     */
    public function getApiKey(): ?string;

    /**
     * Persist the PageSpeed Insights API key.
     *
     * Drivers that are read-only (like the config driver) throw a
     * RuntimeException instead of writing.
     *
     * @since 1.0.0
     *
     * @param  string|null  $key  The API key to store, or null to clear it.
     *
     * @return void
     */
    public function save( ?string $key ): void;

    /**
     * Whether a usable API key is stored.
     *
     * A false return means PageSpeed Insights cannot run at all — an API key
     * is required, not merely recommended.
     *
     * @since 1.0.0
     *
     * @return bool True when an API key is available.
     */
    public function isConfigured(): bool;
}
