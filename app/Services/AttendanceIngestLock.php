<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Serializes every write of device punches (spec 012, decision 3): a manual import, an agent
 * post and the application of held punches. The duplicate check reads the stored punches and
 * then inserts; two of these running at once could both miss the other's punch and store it
 * twice. Inside one PHP process a nested call runs without taking the lock again.
 */
class AttendanceIngestLock
{
    public const NAME = 'attendance-ingest';

    /** Longer than the longest import (the job's 600 s timeout), so a live holder never loses it. */
    private const LEASE_SECONDS = 900;

    private static int $depth = 0;

    /**
     * Run $work while holding the lock, waiting up to $waitSeconds for it.
     *
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException when it stays busy
     */
    public static function run(callable $work, int $waitSeconds): mixed
    {
        if (self::$depth > 0) {
            return $work();
        }

        $lock = Cache::lock(self::NAME, self::LEASE_SECONDS);
        $lock->block(max(0, $waitSeconds));

        self::$depth++;
        try {
            return $work();
        } finally {
            self::$depth--;
            $lock->release();
        }
    }

    /** Whether this process is inside an ingest right now. */
    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
