<?php

/**
 * Recording queue job double.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Support;

use Illuminate\Contracts\Queue\Job as JobContract;
use Throwable;

/**
 * Stands in for the queue's own job wrapper so a test can see what a job
 * asked the queue to do with it.
 *
 * The distinctions this package cares about — released with a delay, failed
 * without retrying, or neither — are invisible when a job is invoked
 * directly, because `release()` and `fail()` on `InteractsWithQueue` quietly
 * no-op without a wrapper. Faking the queue does not help either: that stops
 * the job running at all, and the behaviour under test lives inside `handle()`.
 *
 * `fail()` calls the job's own `failed()` hook, matching what the real
 * wrapper does when a job fails itself from inside `handle()`.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class RecordingQueueJob implements JobContract
{
    /**
     * The delay the job was released with, when it was released.
     *
     * @var int|null
     */
    public ?int $releasedFor = null;

    /**
     * The exception the job failed with, when it failed.
     *
     * @var Throwable|null
     */
    public ?Throwable $failedWith = null;

    /**
     * Whether the job was failed at all, even without an exception.
     *
     * @var bool
     */
    public bool $failed = false;

    /**
     * Whether the job was deleted.
     *
     * @var bool
     */
    public bool $deleted = false;

    /**
     * Build the double.
     *
     * @param  object  $instance  The job being processed.
     * @param  int  $attempts  Which attempt this is.
     */
    public function __construct( protected object $instance, protected int $attempts = 1 )
    {
    }

    /**
     * {@inheritDoc}
     */
    public function uuid()
    {
        return 'recording-queue-job';
    }

    /**
     * {@inheritDoc}
     */
    public function getJobId()
    {
        return 'recording-queue-job';
    }

    /**
     * {@inheritDoc}
     */
    public function payload()
    {
        return [ 'job' => $this->instance::class, 'data' => [] ];
    }

    /**
     * {@inheritDoc}
     */
    public function fire(): void
    {
        //
    }

    /**
     * {@inheritDoc}
     */
    public function release( $delay = 0 ): void
    {
        $this->releasedFor = (int) $delay;
    }

    /**
     * {@inheritDoc}
     */
    public function isReleased()
    {
        return null !== $this->releasedFor;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(): void
    {
        $this->deleted = true;
    }

    /**
     * {@inheritDoc}
     */
    public function isDeleted()
    {
        return $this->deleted;
    }

    /**
     * {@inheritDoc}
     */
    public function isDeletedOrReleased()
    {
        return $this->isDeleted() || $this->isReleased();
    }

    /**
     * {@inheritDoc}
     */
    public function attempts()
    {
        return $this->attempts;
    }

    /**
     * {@inheritDoc}
     */
    public function hasFailed()
    {
        return $this->failed;
    }

    /**
     * {@inheritDoc}
     */
    public function markAsFailed(): void
    {
        $this->failed = true;
    }

    /**
     * {@inheritDoc}
     */
    public function fail( $e = null ): void
    {
        $this->markAsFailed();
        $this->failedWith = $e;
        $this->delete();

        if ( method_exists( $this->instance, 'failed' ) ) {
            $this->instance->failed( $e );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function maxTries()
    {
        return $this->instance->tries ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function maxExceptions()
    {
        return $this->instance->maxExceptions ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function timeout()
    {
        return $this->instance->timeout ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function retryUntil()
    {
        if ( ! method_exists( $this->instance, 'retryUntil' ) ) {
            return null;
        }

        // The real wrapper reads a unix timestamp the queue wrote into the
        // payload from the job's own `retryUntil()`, so the double resolves it
        // the same way rather than reporting "no deadline" for a job that
        // declares one.
        return $this->instance->retryUntil()->getTimestamp();
    }

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return $this->instance::class;
    }

    /**
     * {@inheritDoc}
     */
    public function resolveName()
    {
        return $this->instance::class;
    }

    /**
     * {@inheritDoc}
     */
    public function resolveQueuedJobClass()
    {
        return $this->instance::class;
    }

    /**
     * {@inheritDoc}
     */
    public function getConnectionName()
    {
        return 'recording';
    }

    /**
     * {@inheritDoc}
     */
    public function getQueue()
    {
        return 'default';
    }

    /**
     * {@inheritDoc}
     */
    public function getRawBody()
    {
        return json_encode( $this->payload() );
    }
}
