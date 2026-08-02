<?php

/**
 * One detected score regression.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Alerts;

/**
 * A single category on a single URL and form factor that got worse.
 *
 * Three kinds, kept apart because they are three different pieces of news:
 *
 * - **drop** — the score fell by at least the configured number of points
 *   against the previous run.
 * - **threshold** — the score is below its configured floor, whatever the
 *   previous run said. Absolute rather than comparative, so it also applies
 *   to a URL's very first run.
 * - **stopped** — the category was scored last time and is null now. Worded
 *   apart from a numeric drop on purpose: nothing got slower, the page simply
 *   stopped being measured, and a detector that treated that as "no data to
 *   compare" would say nothing at all — which is the failure this whole
 *   feature exists to prevent.
 *
 * Immutable, and carries enough to render a line without going back to the
 * database, because the notification that renders it may be assembled minutes
 * later in another process.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class Regression
{
    /**
     * The score fell by at least the configured number of points.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const TYPE_DROP = 'drop';

    /**
     * The score is below its configured floor.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const TYPE_THRESHOLD = 'threshold';

    /**
     * The category was scored before and is not scored now.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const TYPE_STOPPED = 'stopped';

    /**
     * Build a regression.
     *
     * @since 1.0.0
     *
     * @param  string  $type  One of the TYPE_* constants.
     * @param  string  $url  The URL that regressed.
     * @param  string  $strategy  The form factor it regressed on.
     * @param  string  $category  The response-side category key, e.g. best-practices.
     * @param  int|null  $currentScore  The new score, null when it stopped being measured.
     * @param  int|null  $previousScore  The score it is being compared with, when there was one.
     * @param  int|null  $threshold  The floor that was breached, when one was.
     * @param  int|null  $resultId  The stored result this was detected on.
     * @param  int|null  $previousResultId  The stored result it was compared with.
     * @param  bool  $degraded  Whether the run it was detected on completed while losing data.
     * @param  string|null  $label  The monitored URL's operator-set label, when it has one.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $url,
        public readonly string $strategy,
        public readonly string $category,
        public readonly ?int $currentScore = null,
        public readonly ?int $previousScore = null,
        public readonly ?int $threshold = null,
        public readonly ?int $resultId = null,
        public readonly ?int $previousResultId = null,
        public readonly bool $degraded = false,
        public readonly ?string $label = null,
    ) {
    }

    /**
     * Rebuild a regression from its array form.
     *
     * The digest buffer is a cache entry, so a regression detected in one
     * process is rendered in another after a round trip through storage that
     * only keeps scalars.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $stored  The stored form.
     *
     * @return self The rebuilt regression.
     */
    public static function fromArray( array $stored ): self
    {
        $integer = static fn ( string $key ): ?int => isset( $stored[ $key ] ) && is_numeric( $stored[ $key ] )
            ? (int) $stored[ $key ]
            : null;

        return new self(
            type: isset( $stored[ 'type' ] ) ? (string) $stored[ 'type' ] : self::TYPE_DROP,
            url: (string) ( $stored[ 'url' ] ?? '' ),
            strategy: (string) ( $stored[ 'strategy' ] ?? '' ),
            category: (string) ( $stored[ 'category' ] ?? '' ),
            currentScore: $integer( 'current_score' ),
            previousScore: $integer( 'previous_score' ),
            threshold: $integer( 'threshold' ),
            resultId: $integer( 'result_id' ),
            previousResultId: $integer( 'previous_result_id' ),
            degraded: true === ( $stored[ 'degraded' ] ?? false ),
            label: isset( $stored[ 'label' ] ) && '' !== (string) $stored[ 'label' ]
                ? (string) $stored[ 'label' ]
                : null,
        );
    }

    /**
     * How many points the score lost, when both runs scored the category.
     *
     * @since 1.0.0
     *
     * @return int|null The drop, or null when there is nothing to subtract.
     */
    public function pointsLost(): ?int
    {
        if ( null === $this->currentScore || null === $this->previousScore ) {
            return null;
        }

        return $this->previousScore - $this->currentScore;
    }

    /**
     * The category name as a person reads it.
     *
     * @since 1.0.0
     *
     * @return string The display name.
     */
    public function categoryName(): string
    {
        return match ( $this->category ) {
            'performance'    => __( 'Performance' ),
            'accessibility'  => __( 'Accessibility' ),
            'best-practices' => __( 'Best Practices' ),
            'seo'            => __( 'SEO' ),
            default          => ucwords( str_replace( '-', ' ', $this->category ) ),
        };
    }

    /**
     * One line describing this regression, ready for a notification.
     *
     * @since 1.0.0
     *
     * @return string The line.
     */
    public function describe(): string
    {
        $replacements = [
            'category'  => $this->categoryName(),
            'url'       => $this->url,
            'strategy'  => $this->strategy,
            'current'   => (string) $this->currentScore,
            'previous'  => (string) $this->previousScore,
            'points'    => (string) $this->pointsLost(),
            'threshold' => (string) $this->threshold,
        ];

        $line = match ( $this->type ) {
            self::TYPE_STOPPED   => __(
                ':category on :url (:strategy) has no score at all this run, and scored :previous last run. The page is no longer being measured for it.',
                $replacements,
            ),
            // "down from" is only true when it actually came down. A score
            // that rose and is still under its floor is a real alert and a
            // false sentence, and a notification nobody can trust the wording
            // of is a notification people stop reading.
            self::TYPE_THRESHOLD => null !== $this->previousScore && $this->previousScore > $this->currentScore
                ? __(
                    ':category on :url (:strategy) scored :current, below the floor of :threshold, down from :previous.',
                    $replacements,
                )
                : __( ':category on :url (:strategy) scored :current, below the floor of :threshold.', $replacements ),
            default              => __(
                ':category on :url (:strategy) fell :points points, from :previous to :current.',
                $replacements,
            ),
        };

        if ( $this->degraded ) {
            $line .= ' ' . __( 'That run completed with data missing, so treat the comparison with care.' );
        }

        return $line;
    }

    /**
     * The regression as an array, for the digest buffer and the hook payload.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The array form.
     */
    public function toArray(): array
    {
        return [
            'type'               => $this->type,
            'url'                => $this->url,
            'strategy'           => $this->strategy,
            'category'           => $this->category,
            'current_score'      => $this->currentScore,
            'previous_score'     => $this->previousScore,
            'points_lost'        => $this->pointsLost(),
            'threshold'          => $this->threshold,
            'result_id'          => $this->resultId,
            'previous_result_id' => $this->previousResultId,
            'degraded'           => $this->degraded,
            'label'              => $this->label,
        ];
    }
}
