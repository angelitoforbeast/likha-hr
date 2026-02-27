<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeBenefit extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'benefit_type_id',
        'is_eligible',
        'amount',
        'effective_from',
        'effective_until',
        'remarks',
    ];

    protected $casts = [
        'is_eligible' => 'boolean',
        'amount' => 'decimal:2',
        'effective_from' => 'date',
        'effective_until' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function benefitType()
    {
        return $this->belongsTo(BenefitType::class);
    }

    /**
     * Check if this benefit is active on a given date.
     */
    public function isActiveOn($date): bool
    {
        $date = \Carbon\Carbon::parse($date);
        if ($date->lt($this->effective_from)) return false;
        if ($this->effective_until && $date->gt($this->effective_until)) return false;
        return $this->is_eligible;
    }
}
