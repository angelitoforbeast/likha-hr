<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDateLock extends Model
{
    protected $fillable = [
        'lock_date',
        'locked_by',
        'reason',
        'source',
        'locked_at',
    ];

    protected $casts = [
        'lock_date' => 'date',
        'locked_at' => 'datetime',
    ];

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * Is the given date locked? Cheap check used by all edit endpoints.
     */
    public static function isLocked(string $date): bool
    {
        return static::whereDate('lock_date', $date)->exists();
    }

    /**
     * Fetch the lock row for a date (null if not locked).
     */
    public static function forDate(string $date): ?self
    {
        return static::whereDate('lock_date', $date)->first();
    }
}
