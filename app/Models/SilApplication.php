<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SilApplication extends Model
{
    use Auditable;

    /** Spec 015: a SIL is a whole day or half a day. */
    public const WHOLE_DAY = 1.0;
    public const HALF_DAY  = 0.5;
    public const ALLOWED_DAYS = [self::WHOLE_DAY, self::HALF_DAY];

    protected $fillable = [
        'employee_id',
        'sil_date',
        'days',
        'reason',
        'applied_by',
    ];

    protected $casts = [
        'sil_date' => 'date',
        'days'     => 'float',
    ];

    protected $attributes = [
        'days' => self::WHOLE_DAY,
    ];

    /** The days of this application; a row without them (older code) is a whole day. */
    public function daysValue(): float
    {
        return $this->days === null ? self::WHOLE_DAY : (float) $this->days;
    }

    public function isHalfDay(): bool
    {
        return $this->daysValue() < self::WHOLE_DAY;
    }

    /** "SIL" for a whole day, "SIL ½" for a half. */
    public function label(): string
    {
        return self::labelFor($this->daysValue());
    }

    public static function labelFor(float $days): string
    {
        return $days < self::WHOLE_DAY ? 'SIL ½' : 'SIL';
    }

    /**
     * The days asked for in a request ("1", "0.5", 1, 0.5; empty is a whole day), or null
     * when it is neither. Used by every filing path so they accept the same values.
     */
    public static function parseDays(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return self::WHOLE_DAY;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $days = (float) $value;

        return in_array($days, self::ALLOWED_DAYS, true) ? $days : null;
    }

    /**
     * Spec 016: a whole-day SIL on a date the employee worked (an attendance day with
     * payable minutes) is not used: the work is paid and the balance is not reduced.
     * A half day is not affected: its other half is the work (spec 015).
     */
    public function scopeUsed($query)
    {
        return $query->where(function ($q) {
            $q->where('days', '<', self::WHOLE_DAY)
              ->orWhereNotExists(function ($sub) {
                  $sub->selectRaw('1')->from('attendance_days')
                      ->whereColumn('attendance_days.employee_id', 'sil_applications.employee_id')
                      ->whereRaw('date(attendance_days.work_date) = date(sil_applications.sil_date)')
                      ->where('attendance_days.payable_work_minutes', '>', 0);
              });
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
