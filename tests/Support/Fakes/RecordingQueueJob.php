<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use Illuminate\Contracts\Queue\Job;

/**
 * Records what a job asked the queue to do.
 *
 * A rate-limited publish calls `release()` rather than failing, and the only
 * way to prove the delay reached the queue is to hand the job a queue that
 * remembers it.
 */
class RecordingQueueJob implements Job
{
    /**
     * @var list<int>
     */
    public array $released = [];

    public bool $deleted = false;

    public function __construct(private int $attempts = 1) {}

    public function release($delay = 0)
    {
        $this->released[] = (int) $delay;

        return 0;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function isReleased(): bool
    {
        return $this->released !== [];
    }

    public function isDeletedOrReleased(): bool
    {
        return $this->deleted || $this->isReleased();
    }

    public function delete()
    {
        $this->deleted = true;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function uuid()
    {
        return 'recording-queue-job';
    }

    public function getJobId()
    {
        return 'recording-queue-job';
    }

    public function payload()
    {
        return [];
    }

    public function fire()
    {
        return null;
    }

    public function hasFailed()
    {
        return false;
    }

    public function markAsFailed()
    {
        return null;
    }

    public function fail($e = null)
    {
        return null;
    }

    public function maxTries()
    {
        return null;
    }

    public function maxExceptions()
    {
        return null;
    }

    public function timeout()
    {
        return null;
    }

    public function retryUntil()
    {
        return null;
    }

    public function retryNow()
    {
        return null;
    }

    public function getName()
    {
        return 'recording';
    }

    public function resolveName()
    {
        return 'recording';
    }

    public function resolveQueuedJobClass()
    {
        return null;
    }

    public function getConnectionName()
    {
        return 'sync';
    }

    public function getQueue()
    {
        return 'default';
    }

    public function getRawBody()
    {
        return '';
    }
}
