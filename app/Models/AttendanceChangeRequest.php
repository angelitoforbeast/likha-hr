<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attendance change asked by HR staff or an admin for one employee, date
 * and field. An "entry" on the Approvals page is every pending row of one
 * employee and date; it is approved or rejected as a whole.
 */
class AttendanceChangeRequest extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const TIME_FIELDS = ['time_in', 'lunch_out', 'lunch_in', 'time_out'];

    public const FIELD_OVERTIME = 'approved_overtime_minutes';
    public const FIELD_SHIFT    = 'shift_id';
    public const FIELD_SIL      = 'sil';
    public const FIELD_REST_DAY = 'rest_day';

    public const FIELD_LABELS = [
        'time_in'                   => 'Time In',
        'lunch_out'                 => 'Lunch Out',
        'lunch_in'                  => 'Lunch In',
        'time_out'                  => 'Time Out',
        'approved_overtime_minutes' => 'Approved overtime',
        'shift_id'                  => 'Shift',
        'sil'                       => 'SIL',
        'rest_day'                  => 'Rest day',
    ];

    // work_date is deliberately not cast: it is kept as the 'Y-m-d' text on every
    // database driver, so the unique index compares the same value everywhere.
    protected $fillable = [
        'employee_id', 'work_date', 'field', 'new_value', 'old_value', 'day_state', 'reason',
        'origin', 'batch_id', 'requested_by', 'status', 'pending_marker', 'version', 'changed_at',
        'decided_by', 'decided_at', 'decision_reason', 'superseded_at', 'applied_type', 'applied_id',
    ];

    protected $casts = [
        'day_state'     => 'array',
        'version'       => 'integer',
        'changed_at'    => 'datetime',
        'decided_at'    => 'datetime',
        'superseded_at' => 'datetime',
    ];

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

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isTimeField(): bool
    {
        return in_array($this->field, self::TIME_FIELDS, true);
    }

    public function fieldLabel(): string
    {
        return self::FIELD_LABELS[$this->field] ?? $this->field;
    }

    public function requesterName(): string
    {
        return $this->requester->name ?? 'Unknown';
    }

    /** The work date as 'Y-m-d' whatever the driver returned. */
    public function dateString(): string
    {
        return substr((string) $this->work_date, 0, 10);
    }

    /** The kind of change, for the list and its filter. */
    public function kind(): string
    {
        return match (true) {
            $this->isTimeField()                   => 'times',
            $this->field === self::FIELD_OVERTIME  => 'overtime',
            $this->field === self::FIELD_SHIFT     => 'shift',
            $this->field === self::FIELD_SIL       => 'sil',
            default                                => 'rest_day',
        };
    }

    /**
     * A stored value of this field as a person reads it. $shiftNames maps shift
     * id to name; without it a shift is shown by its number.
     */
    public function displayValue(?string $value, array $shiftNames = [], bool $isRequested = true): string
    {
        if ($this->isTimeField()) {
            if ($value === null || $value === '') {
                return $isRequested ? 'cleared' : 'none';
            }

            return Carbon::parse('2000-01-01 ' . $value)->format('g:i A');
        }

        return match ($this->field) {
            self::FIELD_OVERTIME => $value === null || $value === ''
                ? 'automatic'
                : rtrim(rtrim(number_format(((int) $value) / 60, 2, '.', ''), '0'), '.') . ' h',
            self::FIELD_SHIFT => $value === null ? 'none' : ($shiftNames[(int) $value] ?? ('Shift #' . $value)),
            self::FIELD_SIL => match ($value) {
                'apply'   => 'Apply SIL',
                'remove'  => 'Remove SIL',
                'applied' => 'SIL applied',
                default   => 'No SIL',
            },
            self::FIELD_REST_DAY => match ($value) {
                'add_day_off'     => 'Make it a rest day',
                'cancel_day_off'  => 'Cancel the rest day (must work)',
                'remove_override' => 'Remove the rest-day override',
                'day_off'         => 'Rest day set',
                default           => 'No override',
            },
            default => (string) $value,
        };
    }

    /**
     * This request as the calendar shows it on its day: in the cell's data when the
     * page is built, and in the reply to a save so the page can show it at once.
     * $official is the official value of the field now, in the stored form.
     */
    public function toDayItem(?string $official, array $shiftNames, ?int $viewerId): array
    {
        $item = [
            'id'        => $this->id,
            'status'    => $this->status,
            'field'     => $this->field,
            'label'     => $this->fieldLabel(),
            'requested' => $this->displayValue($this->new_value, $shiftNames),
            'requester' => $this->requesterName(),
            'mine'      => (int) $this->requested_by === (int) $viewerId,
        ];

        if ($this->isPending()) {
            return $item + [
                'official'   => $this->displayValue($official, $shiftNames, false),
                'reason'     => $this->reason,
                'asked_at'   => $this->created_at->format('M d, Y g:i A'),
                'changeable' => $this->isTimeField(),
            ];
        }

        return $item + [
            'reason' => $this->decision_reason,
            'by'     => $this->decider->name ?? 'Unknown',
        ];
    }

    /** Number of pending entries: employee and date pairs with at least one pending row. */
    public static function pendingEntryCount(?string $from = null, ?string $to = null): int
    {
        $pairs = static::query()
            ->where('status', self::STATUS_PENDING)
            ->when($from, fn ($q) => $q->where('work_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('work_date', '<=', $to))
            ->select('employee_id', 'work_date')
            ->distinct();

        // Counted by the database: this runs on every page the CEO opens.
        return (int) \Illuminate\Support\Facades\DB::query()->fromSub($pairs, 'entries')->count();
    }
}
