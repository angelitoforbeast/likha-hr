<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A daily rate of an employee for a range of dates.
 *
 * Spec 013: a rate saved by anyone but the CEO is a pending row until the CEO approves it.
 * Every query of this model sees approved rows only (the "approved" global scope), so
 * payroll, the current rate, reports and the overlap checks never see a pending or
 * rejected row. Code that must show those rows asks for them with anyStatus().
 */
class EmployeeRate extends Model
{
    use HasFactory, Auditable;

    public const STATUS_APPROVED = 'approved';
    public const STATUS_PENDING  = 'pending';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'employee_id',
        'daily_rate',
        'effective_date',
        'effective_until',
        'remarks',
        'status',
        'replaces_rate_id',
        'requested_by',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected $casts = [
        'daily_rate'      => 'decimal:2',
        'effective_date'  => 'date',
        'effective_until' => 'date',
        'decided_at'      => 'datetime',
    ];

    /** Not shown in the audit log: it only mirrors the status. */
    protected array $auditExclude = ['approved_marker'];

    /** The audit log keeps an old date as stored ("2027-06-30"), not as UTC. */
    protected bool $auditRawDates = true;

    protected static function booted(): void
    {
        static::addGlobalScope('approved', function (Builder $query) {
            $query->where($query->getModel()->getTable() . '.status', self::STATUS_APPROVED);
        });

        // 1 on an approved row, NULL on any other: the unique index allows one approved
        // rate per employee and start date, and any number of requests beside it.
        static::saving(function (EmployeeRate $rate) {
            $rate->status ??= self::STATUS_APPROVED;
            $rate->approved_marker = $rate->status === self::STATUS_APPROVED ? 1 : null;
        });
    }

    /** Rows of every status: approved, pending and rejected. */
    public function scopeAnyStatus(Builder $query): Builder
    {
        return $query->withoutGlobalScope('approved');
    }

    /** A pending or rejected row is reached by its URL too (to withdraw it, to decide it). */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::anyStatus()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /** The range as a person reads it: "Nov 16, 2026 · ongoing" or "Jan 01, 2026 – Jun 30, 2027". */
    public function rangeText(): string
    {
        return $this->effective_date->format('M d, Y')
            . ($this->effective_until ? ' – ' . $this->effective_until->format('M d, Y') : ' · ongoing');
    }

    /**
     * Get the active daily rate for an employee on a given date.
     * Returns the approved rate with the latest effective_date <= $date.
     */
    public static function getActiveRate(int $employeeId, string $date): ?float
    {
        $rate = static::where('employee_id', $employeeId)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->first();

        return $rate ? (float) $rate->daily_rate : null;
    }
}
