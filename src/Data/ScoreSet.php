<?php

/**
 * Lighthouse category score set.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Data;

use ArtisanPackUI\PageSpeedInsights\Support\CategoryTranslator;

/**
 * The 0-100 category scores from a Lighthouse run.
 *
 * Holds every category the response carried, keyed by its lower-kebab
 * response key, and exposes typed accessors for the four this package stores
 * as dedicated columns. A category Lighthouse adds later still lands in
 * {@see self::all()} rather than being dropped, and one that goes away leaves
 * its accessor returning null rather than throwing.
 *
 * Lighthouse reports `score` as a 0-1 float or null; the values here are
 * `round( score * 100 )`, and null stays null.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class ScoreSet
{
    /**
     * Build the score set.
     *
     * @since 1.0.0
     *
     * @param  array<string, int|null>  $scores  Response key to 0-100 score.
     */
    public function __construct( protected array $scores = [] )
    {
    }

    /**
     * The performance score.
     *
     * @since 1.0.0
     *
     * @return int|null The 0-100 score, or null when absent or unscored.
     */
    public function performance(): ?int
    {
        return $this->get( 'performance' );
    }

    /**
     * The accessibility score.
     *
     * @since 1.0.0
     *
     * @return int|null The 0-100 score, or null when absent or unscored.
     */
    public function accessibility(): ?int
    {
        return $this->get( 'accessibility' );
    }

    /**
     * The best practices score.
     *
     * @since 1.0.0
     *
     * @return int|null The 0-100 score, or null when absent or unscored.
     */
    public function bestPractices(): ?int
    {
        return $this->get( 'best-practices' );
    }

    /**
     * The SEO score.
     *
     * @since 1.0.0
     *
     * @return int|null The 0-100 score, or null when absent or unscored.
     */
    public function seo(): ?int
    {
        return $this->get( 'seo' );
    }

    /**
     * A single category score by response key.
     *
     * @since 1.0.0
     *
     * @param  string  $category  Category name in either spelling.
     *
     * @return int|null The 0-100 score, or null when absent or unscored.
     */
    public function get( string $category ): ?int
    {
        $key = CategoryTranslator::toResponseKey( $category );

        return $this->scores[ $key ] ?? null;
    }

    /**
     * Whether the response carried this category at all, scored or not.
     *
     * @since 1.0.0
     *
     * @param  string  $category  Category name in either spelling.
     *
     * @return bool True when the category was present in the response.
     */
    public function has( string $category ): bool
    {
        return array_key_exists( CategoryTranslator::toResponseKey( $category ), $this->scores );
    }

    /**
     * Every category the response carried.
     *
     * @since 1.0.0
     *
     * @return array<string, int|null> Response key to 0-100 score.
     */
    public function all(): array
    {
        return $this->scores;
    }

    /**
     * Categories the response carried that this package has no typed
     * accessor for — a new Lighthouse category, most likely.
     *
     * @since 1.0.0
     *
     * @return array<int, string> Response keys.
     */
    public function unrecognized(): array
    {
        return array_values( array_filter(
            array_keys( $this->scores ),
            static fn ( string $key ): bool => ! CategoryTranslator::isKnown( $key ),
        ) );
    }

    /**
     * The scores as a plain array for storage.
     *
     * @since 1.0.0
     *
     * @return array<string, int|null> Response key to 0-100 score.
     */
    public function toArray(): array
    {
        return $this->all();
    }
}
