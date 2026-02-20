<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'zkteco_id',
        'full_name',
        'status',
        'default_shift_id',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    public function defaultShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'default_shift_id');
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(EmployeeShiftAssignment::class)->orderByDesc('effective_date');
    }

    public function employeeRates(): HasMany
    {
        return $this->hasMany(EmployeeRate::class)->orderByDesc('effective_date');
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function attendanceDays(): HasMany
    {
        return $this->hasMany(AttendanceDay::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(AttendanceOverride::class);
    }

    public function payRates(): HasMany
    {
        return $this->hasMany(PayRate::class);
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    /**
     * Get the active shift for this employee on a given date.
     * Falls back to default_shift_id if no assignment exists.
     */
    public function getShiftForDate(string $date): ?Shift
    {
        $shift = EmployeeShiftAssignment::getActiveShift($this->id, $date);

        if ($shift) {
            return $shift;
        }

        return $this->defaultShift;
    }

    /**
     * Get the active daily rate for this employee on a given date.
     */
    public function getRateForDate(string $date): ?float
    {
        return EmployeeRate::getActiveRate($this->id, $date);
    }

    /**
     * Get the current (latest) shift assignment.
     */
    public function getCurrentShift(): ?Shift
    {
        return $this->getShiftForDate(now()->toDateString());
    }

    /**
     * Get the current (latest) daily rate.
     */
    public function getCurrentRate(): ?float
    {
        return $this->getRateForDate(now()->toDateString());
    }
}
